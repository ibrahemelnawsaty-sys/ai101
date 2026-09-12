<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\AssignmentStatus;
use App\Enums\CohortStatus;
use App\Enums\SessionStatus;
use App\Events\AssignmentReminderRequested;
use App\Mail\AtharLetter;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Profile;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Mail\CohortAudience;
use App\Services\Time\Clock;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The notices the calendar sends on its own: a session tomorrow, a session in
 * an hour, a session starting now, and an assignment deadline two days and six
 * hours away (PRD §9.16.1).
 *
 * WHY THIS EXISTS
 * The notification matrix promised these five, the preferences screen offered
 * a switch for each, and nothing sent one: the only scheduled task was
 * attendance reconciliation (D-83).
 *
 * HOW A NOTICE FIRES EXACTLY ONCE
 * Each notice has a MARK — S-24h, S-1h, S, D-48h, D-6h — and fires on the first
 * run inside [mark, mark + grace). Before anything is written it is CLAIMED:
 * one row in `scheduled_notices`, unique on (kind, subject, target instant),
 * inserted with insertOrIgnore. The run that inserts the row sends; a second
 * cron tick or an overlapping process inserts nothing and sends nothing. The
 * claim and the sending share one transaction, so a run that fails half way
 * leaves no claim behind and the next tick tries again.
 *
 * The target instant is part of the key on purpose: a session moved to another
 * day is a new target and is reminded again for its new time.
 *
 * WHY A GRACE AND NOT "ANY TIME BEFORE"
 * The words are fixed by the mark: the 24-hour letter says "tomorrow"
 * (emails.session_reminder.when_24h), the one-hour letter "in an hour", the
 * start notice "live now". A cron that was down for
 * five hours must not send "tomorrow" about a session that starts this evening,
 * and a session created forty minutes before it starts has no 24-hour reminder
 * to send. After the grace the notice is skipped, not sent late. The grace
 * lengths are a temporary assumption awaiting sign-off (D-83).
 *
 * @see PRD §9.10, §9.16.1 · FR-NOTIF-10, FR-NOTIF-11, FR-NOTIF-14 · D-83
 */
final class ScheduledNotices
{
    /** @var array<string, array{0: int, 1: int}> kind => [minutes before the target, grace in minutes] */
    public const SESSION_MARKS = [
        'session_reminder_24h' => [24 * 60, 60],
        'session_reminder_1h' => [60, 15],
        'session_started' => [0, 15],
    ];

    /** @var array<string, array{0: int, 1: int}> kind => [minutes before the deadline, grace in minutes] */
    public const ASSIGNMENT_MARKS = [
        'assignment_due_48h' => [48 * 60, 60],
        'assignment_due_6h' => [6 * 60, 60],
    ];

    /**
     * A finished cohort is sent nothing. An upcoming one is: its first session
     * may be tomorrow while the status still says upcoming, and its seated
     * trainees are the people the intro reminder is for.
     */
    private const LIVE_COHORTS = [CohortStatus::Upcoming->value, CohortStatus::Open->value, CohortStatus::Running->value];

    public function __construct(
        private readonly AttendanceWindow $window,
        private readonly CohortAudience $audience,
        private readonly InAppNotifier $notifier,
    ) {}

    /**
     * @return array{sessions: int, assignments: int} how many notices this run claimed
     */
    public function run(CarbonImmutable $now): array
    {
        return [
            'sessions' => $this->sessions($now),
            'assignments' => $this->assignments($now),
        ];
    }

    /**
     * True when $now is inside [target - before, target - before + grace).
     * The lower edge is inclusive and the upper edge exclusive, to the second.
     */
    public static function isDue(CarbonImmutable $target, int $minutesBefore, int $graceMinutes, CarbonImmutable $now): bool
    {
        $mark = $target->subMinutes($minutesBefore);

        return $now->greaterThanOrEqualTo($mark) && $now->lessThan($mark->addMinutes($graceMinutes));
    }

    // ------------------------------------------------------------- sessions

    private function sessions(CarbonImmutable $now): int
    {
        $today = Clock::toRiyadh($now);

        // A 24-hour mark lies at most a day ahead and a start mark's grace at
        // most minutes behind; three Riyadh days cover both without scanning
        // the whole schedule every minute.
        $sessions = Session::query()
            ->with('trainer.profile')
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereIn('cohort_id', Cohort::query()->whereIn('status', self::LIVE_COHORTS)->select('id'))
            ->whereDate('date', '>=', $today->subDay()->toDateString())
            ->whereDate('date', '<=', $today->addDays(2)->toDateString())
            ->get();

        $claimed = 0;

        foreach ($sessions as $session) {
            $start = $this->window->startsAt($session);

            foreach (self::SESSION_MARKS as $kind => [$before, $grace]) {
                if (! self::isDue($start, $before, $grace, $now)) {
                    continue;
                }

                $claimed += $this->claimAndSend($kind, (string) $session->getKey(), $start, $now,
                    fn () => $this->sendSession($kind, $session, $start, $now));
            }
        }

        return $claimed;
    }

    private function sendSession(string $kind, Session $session, CarbonImmutable $start, CarbonImmutable $now): void
    {
        $cohortId = (string) $session->getAttribute('cohort_id');
        $title = (string) $session->getAttribute('title');
        $ids = $this->audience->participants($cohortId)->map(static fn (User $user): string => (string) $user->getKey());

        if ($kind === 'session_started') {
            // The platform only (PRD §9.16.1): a letter arriving after the
            // session has begun is read after it has ended.
            $this->notifier->notify(
                $ids,
                'session_started',
                (string) __('notifications.types.session_started.title', ['session' => $title]),
                (string) __('notifications.types.session_started.body'),
                route('live'),
                $now,
            );

            return;
        }

        $when = Dates::dateTime($start);

        $this->notifier->notify(
            $ids,
            'session_reminder',
            (string) __('notifications.types.session_reminder.title', ['session' => $title]),
            (string) __('notifications.types.session_reminder.body', ['datetime' => $when]),
            route('live'),
            $now,
        );

        $values = [
            'session' => $title,
            'datetime' => $when,
            'trainer' => $this->trainerName($session),
            'when' => (string) __($kind === 'session_reminder_24h'
                ? 'emails.session_reminder.when_24h'
                : 'emails.session_reminder.when_1h'),
        ];

        foreach ($this->audience->reachable($cohortId, 'session_reminder') as $user) {
            $this->mail($user, new AtharLetter(
                copyKey: 'emails.session_reminder',
                values: $values,
                ctaUrl: route('live'),
            ), $kind);
        }
    }

    private function trainerName(Session $session): string
    {
        $trainer = $session->getRelationValue('trainer');
        $profile = $trainer instanceof User ? $trainer->getRelationValue('profile') : null;
        $name = $profile instanceof Profile ? Present::text($profile->getAttribute('full_name_ar')) : null;

        return $name ?? (string) __('emails.session_reminder.trainer_fallback');
    }

    // ---------------------------------------------------------- assignments

    private function assignments(CarbonImmutable $now): int
    {
        $assignments = Assignment::query()
            ->where('status', AssignmentStatus::Published->value)
            ->whereIn('cohort_id', Cohort::query()->whereIn('status', self::LIVE_COHORTS)->select('id'))
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $now->addHours(49))
            ->get();

        $claimed = 0;

        foreach ($assignments as $assignment) {
            $dueAt = $assignment->getAttribute('due_at');

            if (! $dueAt instanceof \DateTimeInterface) {
                continue;
            }

            $due = Clock::toUtc($dueAt);

            foreach (self::ASSIGNMENT_MARKS as $kind => [$before, $grace]) {
                if (! self::isDue($due, $before, $grace, $now)) {
                    continue;
                }

                $claimed += $this->claimAndSend($kind, (string) $assignment->getKey(), $due, $now,
                    fn () => $this->sendAssignment($assignment, $due, $now));
            }
        }

        return $claimed;
    }

    /**
     * The same two halves the trainer's "remind who has not submitted" button
     * writes (FR-ASGN-30): the platform notice now, to those who have handed
     * in no version, and the letter through the queued listener, which checks
     * the audience again when it runs.
     */
    private function sendAssignment(Assignment $assignment, CarbonImmutable $due, CarbonImmutable $now): void
    {
        $cohortId = (string) $assignment->getAttribute('cohort_id');
        $title = (string) $assignment->getAttribute('title');
        $pending = $this->audience->yetToSubmit($cohortId, (string) $assignment->getKey());

        if ($pending->isEmpty()) {
            return;
        }

        $values = ['assignment' => $title, 'countdown' => Present::durationLabel($due, $now)];

        $this->notifier->notify(
            $pending->map(static fn (User $user): string => (string) $user->getKey()),
            'assignment_due_reminder',
            (string) __('notifications.types.assignment_due_reminder.title', $values),
            (string) __('notifications.types.assignment_due_reminder.body', $values),
            route('assignments.show', ['assignment' => $assignment->getKey()]),
            $now,
        );

        AssignmentReminderRequested::dispatch(
            cohortId: $cohortId,
            assignmentId: (string) $assignment->getKey(),
            assignmentTitle: $title,
            dueAtIso: $due->toIso8601ZuluString(),
            dueAtLabel: Dates::dateTime($due),
        );
    }

    // -------------------------------------------------------------- plumbing

    /**
     * @param  callable(): void  $send
     * @return int 1 when this run claimed and sent the notice, 0 otherwise
     */
    private function claimAndSend(string $kind, string $subjectId, CarbonImmutable $target, CarbonImmutable $now, callable $send): int
    {
        try {
            return DB::transaction(function () use ($kind, $subjectId, $target, $now, $send): int {
                $inserted = DB::table('scheduled_notices')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'kind' => $kind,
                    'subject_id' => $subjectId,
                    'target_at' => $target->format('Y-m-d H:i:s'),
                    'created_at' => $now->format('Y-m-d H:i:s'),
                ]);

                if ($inserted === 0) {
                    return 0;
                }

                $send();

                return 1;
            });
        } catch (\Throwable $failure) {
            // One notice that cannot be written must not stop the others. The
            // claim rolled back with it, so the next tick inside the grace
            // tries again. No message: it may carry personal data (art. 12).
            Log::error('notices.scheduled_failed', [
                'kind' => $kind,
                'subject_id' => $subjectId,
                'exception' => $failure::class,
                'at' => $failure->getFile().':'.$failure->getLine(),
            ]);

            return 0;
        }
    }

    private function mail(User $user, AtharLetter $letter, string $kind): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send($letter);
        } catch (\Throwable $exception) {
            // One unreachable address must not stop the rest of the cohort.
            Log::warning('mail.scheduled_notice_failed', [
                'kind' => $kind,
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
