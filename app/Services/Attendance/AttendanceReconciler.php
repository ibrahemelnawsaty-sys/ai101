<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Models\Attendance;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\Session;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use App\Support\AttendanceCounting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The every-fifteen-minutes reconciliation job.
 *
 *  · BR-08 anyone who never checked in to a finished session becomes absent
 *  · BR-09 anyone who checked in but never checked out, once the check-out
 *          window has closed, becomes incomplete and the trainer is notified
 *  · the attendance rate of every participant in the touched cohorts is
 *    recomputed from the actual rows
 *
 * The job is bounded: it only revisits sessions inside a short look-back
 * window, because shared hosting caps a single execution at thirty seconds.
 *
 * @see BR-08, BR-09, BR-07 · PRD §9.9.5 · CONSTITUTION art. 10
 */
final class AttendanceReconciler
{
    private const DEFAULT_LOOKBACK_DAYS = 3;

    private const SESSION_CHUNK = 50;

    /** Notification matrix slug of PRD §9.16.1, stored in notifications.type. */
    private const NOTIFICATION_TYPE_INCOMPLETE = 'attendance_incomplete';

    public function __construct(
        private readonly AttendanceWindow $window,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{sessions:int, absent:int, incomplete:int, cohorts:int, enrollments:int}
     */
    public function run(?CarbonImmutable $at = null, ?int $lookbackDays = null): array
    {
        $now = $at ?? Clock::now();
        $days = $lookbackDays ?? $this->lookbackDays();

        $absent = 0;
        $incomplete = 0;
        $processed = 0;
        /** @var array<string, true> $touchedCohorts */
        $touchedCohorts = [];

        $riyadhToday = $now->setTimezone(Clock::displayTimezone());
        $from = $riyadhToday->subDays($days)->format('Y-m-d');
        $to = $riyadhToday->format('Y-m-d');

        // whereDate, not whereBetween: `sessions.date` is a DATE column in MySQL
        // but the model's `date` cast writes `Y-m-d H:i:s`, which SQLite keeps
        // verbatim. A raw string comparison then puts "2026-10-12 00:00:00"
        // after "2026-10-12" and silently drops every session of the current
        // day - the exact day BR-08 and BR-09 are about. whereDate normalises
        // the column on both engines (PROJECT-CONTRACT portability rule).
        Session::query()
            ->where('status', '!=', SessionStatus::Cancelled)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->chunkById(self::SESSION_CHUNK, function (Collection $sessions) use (
                $now,
                &$absent,
                &$incomplete,
                &$processed,
                &$touchedCohorts,
            ): void {
                foreach ($sessions as $session) {
                    if (! $session instanceof Session) {
                        continue;
                    }

                    if (! $this->window->hasEnded($session, $now)) {
                        continue;
                    }

                    $processed++;
                    $absent += $this->markAbsentees($session, $now);
                    $incomplete += $this->markIncomplete($session, $now);

                    $cohortId = $session->getAttribute('cohort_id');

                    if (is_string($cohortId) && $cohortId !== '') {
                        $touchedCohorts[$cohortId] = true;
                    }
                }
            });

        $cohorts = $this->cohortsToRefresh(array_keys($touchedCohorts));
        $enrollments = 0;

        foreach ($cohorts as $cohort) {
            $enrollments += $this->recomputeAttendanceRate($cohort, $now);
        }

        return [
            'sessions' => $processed,
            'absent' => $absent,
            'incomplete' => $incomplete,
            'cohorts' => $cohorts->count(),
            'enrollments' => $enrollments,
        ];
    }

    /**
     * BR-08 — every active participant without a row for a finished session is
     * marked absent. insertOrIgnore leans on the unique index so a check-in
     * landing in the same instant always wins.
     *
     * @return int number of rows created
     */
    public function markAbsentees(Session $session, ?CarbonImmutable $at = null): int
    {
        $now = $at ?? Clock::now();

        if ($this->window->isCancelled($session) || ! $this->window->hasEnded($session, $now)) {
            return 0;
        }

        $participantIds = $this->activeParticipantIds($session->getAttribute('cohort_id'));

        if ($participantIds === []) {
            return 0;
        }

        $recorded = Attendance::query()
            ->where('session_id', $session->getKey())
            ->pluck('user_id')
            ->all();

        $missing = array_values(array_diff($participantIds, array_map('strval', $recorded)));

        if ($missing === []) {
            return 0;
        }

        $timestamp = $now->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($missing as $userId) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'session_id' => (string) $session->getKey(),
                'user_id' => $userId,
                'check_in_at' => null,
                'check_out_at' => null,
                'status' => AttendanceStatus::Absent->value,
                'is_manual' => false,
                'edited_by' => null,
                'edit_reason' => null,
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        return DB::transaction(function () use ($rows, $session, $missing): int {
            $this->audit->log(
                action: AuditLogger::ATTENDANCE_MARKED_ABSENT,
                entity: $session,
                before: null,
                after: ['user_ids' => $missing, 'count' => count($missing)],
                actor: null,
            );

            $created = DB::table('attendances')->insertOrIgnore($rows);

            return $created;
        });
    }

    /**
     * BR-09 — a check-in without a check-out becomes incomplete once E+30m has
     * passed, and the session trainer is notified once per session.
     *
     * @return int number of rows converted
     */
    public function markIncomplete(Session $session, ?CarbonImmutable $at = null): int
    {
        $now = $at ?? Clock::now();

        if ($this->window->isCancelled($session) || ! $this->window->checkOutWindowHasClosed($session, $now)) {
            return 0;
        }

        $records = Attendance::query()
            ->where('session_id', $session->getKey())
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->whereIn('status', AttendanceCounting::physicallyPresentValues())
            ->get();

        if ($records->isEmpty()) {
            return 0;
        }

        $converted = DB::transaction(function () use ($records): int {
            $count = 0;

            foreach ($records as $record) {
                if (! $record instanceof Attendance) {
                    continue;
                }

                $before = $this->audit->snapshot($record, ['id', 'session_id', 'user_id', 'status', 'check_in_at', 'check_out_at']);
                $record->setAttribute('status', AttendanceStatus::Incomplete);

                $this->audit->log(
                    action: AuditLogger::ATTENDANCE_MARKED_INCOMPLETE,
                    entity: $record,
                    before: $before,
                    after: $this->audit->snapshot($record, ['id', 'session_id', 'user_id', 'status', 'check_in_at', 'check_out_at']),
                    actor: null,
                );

                $record->save();
                $count++;
            }

            return $count;
        });

        if ($converted > 0) {
            $this->notifyTrainer($session, $converted, $now);
        }

        return $converted;
    }

    /**
     * Recompute attendance_rate for every active participant of the cohort.
     *
     * The denominator is the set of non-cancelled sessions that have already
     * ended; the numerator is the rows whose status counts as attended.
     *
     * @return int number of enrolments updated
     */
    public function recomputeAttendanceRate(Cohort $cohort, ?CarbonImmutable $at = null): int
    {
        $now = $at ?? Clock::now();

        $endedSessionIds = $this->endedSessionIds($cohort, $now);
        $total = count($endedSessionIds);

        $participantIds = $this->activeParticipantIds($cohort->getKey());

        if ($participantIds === []) {
            return 0;
        }

        /** @var array<string, int> $attended */
        $attended = [];

        if ($total > 0) {
            $rows = DB::table('attendances')
                ->whereIn('session_id', $endedSessionIds)
                ->whereIn('user_id', $participantIds)
                ->whereIn('status', AttendanceCounting::countedAsAttendedValues())
                ->groupBy('user_id')
                ->selectRaw('user_id, count(*) as attended_count')
                ->get();

            foreach ($rows as $row) {
                $attended[(string) $row->user_id] = (int) $row->attended_count;
            }
        }

        $updated = 0;

        foreach ($participantIds as $userId) {
            $rate = $total === 0
                ? 0.0
                : round(((float) ($attended[$userId] ?? 0) / (float) $total) * 100, 2);

            $updated += Enrollment::query()
                ->where('cohort_id', $cohort->getKey())
                ->where('user_id', $userId)
                ->where(function (EloquentBuilder $query) use ($rate): void {
                    $query->whereNull('attendance_rate')->orWhere('attendance_rate', '!=', $rate);
                })
                ->update([
                    'attendance_rate' => $rate,
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);
        }

        if ($updated > 0) {
            $this->audit->record(
                action: AuditLogger::ATTENDANCE_RATE_RECOMPUTED,
                entityType: $cohort->getMorphClass(),
                entityId: (string) $cohort->getKey(),
                before: null,
                after: ['sessions_counted' => $total, 'enrollments_updated' => $updated],
                actorId: null,
            );
        }

        return $updated;
    }

    /**
     * Identifiers of the cohort's non-cancelled sessions that have ended.
     *
     * @return list<string>
     */
    public function endedSessionIds(Cohort $cohort, CarbonImmutable $at): array
    {
        $sessions = Session::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', '!=', SessionStatus::Cancelled)
            ->get(['id', 'date', 'start_time', 'end_time', 'status']);

        $ids = [];

        foreach ($sessions as $session) {
            if (! $session instanceof Session) {
                continue;
            }

            if ($this->window->hasEnded($session, $at)) {
                $ids[] = (string) $session->getKey();
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $touchedCohortIds
     * @return Collection<int, Cohort>
     */
    private function cohortsToRefresh(array $touchedCohortIds): Collection
    {
        /** @var Collection<int, Cohort> $cohorts */
        $cohorts = Cohort::query()
            ->where(function (EloquentBuilder $query) use ($touchedCohortIds): void {
                $query->where('status', CohortStatus::Running);

                if ($touchedCohortIds !== []) {
                    $query->orWhereIn('id', $touchedCohortIds);
                }
            })
            ->get();

        return $cohorts;
    }

    /**
     * @return list<string>
     */
    private function activeParticipantIds(mixed $cohortId): array
    {
        if (! is_string($cohortId) || $cohortId === '') {
            return [];
        }

        return Enrollment::query()
            ->where('cohort_id', $cohortId)
            ->where('role_in_cohort', EnrollmentRole::Participant)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * BR-09 — one notification per session, addressed to the trainer.
     *
     * `sessions.trainer_id` is nullable (PRD §7.3), and PRD §9.9.5 makes the
     * notice mandatory all the same. So when no trainer is named on the row the
     * cohort's own trainers are notified: the enrolment table is what carries
     * the trainer of a cohort (PRD §4.4). Dropping the notice because a column
     * is null would fail open on a rule that exists to protect the trainee.
     */
    private function notifyTrainer(Session $session, int $count, CarbonImmutable $now): void
    {
        $recipients = $this->trainerRecipients($session);

        if ($recipients === []) {
            return;
        }

        // No de-duplication query is needed: a row is converted to incomplete
        // exactly once, so a later run finds nothing to convert and never
        // reaches this method again for the same session.
        //
        // The type slug matches the notification matrix of PRD §9.16.1, so the
        // trainer's preferences apply to it like any other notice.
        $replacements = [
            'count' => (string) $count,
            'session' => (string) $session->getAttribute('title'),
        ];

        foreach ($recipients as $trainerId) {
            $this->storeIncompleteNotice($session, $trainerId, $replacements, $now);
        }
    }

    /**
     * Who receives the BR-09 notice for this session.
     *
     * @return list<string>
     */
    private function trainerRecipients(Session $session): array
    {
        $trainerId = $session->getAttribute('trainer_id');

        if (is_string($trainerId) && $trainerId !== '') {
            return [$trainerId];
        }

        $cohortId = $session->getAttribute('cohort_id');

        if (! is_string($cohortId) || $cohortId === '') {
            return [];
        }

        return Enrollment::query()
            ->where('cohort_id', $cohortId)
            ->where('role_in_cohort', EnrollmentRole::Trainer)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function storeIncompleteNotice(
        Session $session,
        string $trainerId,
        array $replacements,
        CarbonImmutable $now,
    ): void {
        $notification = new Notification();
        $notification->setAttribute('user_id', $trainerId);
        $notification->setAttribute('type', self::NOTIFICATION_TYPE_INCOMPLETE);
        $notification->setAttribute(
            'title',
            (string) __('notifications.attendance.incomplete_summary.title', $replacements),
        );
        $notification->setAttribute(
            'body',
            (string) __('notifications.attendance.incomplete_summary.body', $replacements),
        );
        $notification->setAttribute('link', $this->trainerSessionLink($session));
        $notification->setAttribute('is_read', false);
        $notification->setAttribute('read_at', null);
        $notification->setAttribute('channel', 'in_app');
        $notification->setAttribute('created_at', $now);
        $notification->setAttribute('updated_at', $now);
        $notification->save();
    }

    private function trainerSessionLink(Session $session): ?string
    {
        $routeName = (string) config('athar.routes.trainer_session', 'trainer.sessions');

        if (! Route::has($routeName)) {
            return null;
        }

        return route($routeName, ['session' => $session->getKey()]);
    }

    private function lookbackDays(): int
    {
        $configured = config('athar.attendance.reconcile_lookback_days');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_LOOKBACK_DAYS;
    }
}
