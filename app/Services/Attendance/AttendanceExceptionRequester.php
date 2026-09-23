<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceExceptionStatus;
use App\Enums\AttendanceExceptionType;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Events\AttendanceExceptionApproved;
use App\Events\AttendanceExceptionRejected;
use App\Events\AttendanceExceptionRequested;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\AttendanceExceptionRequest;
use App\Models\Enrollment;
use App\Models\Session;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\InAppNotifier;
use App\Services\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A participant's request to be excused for an absence or an unexcused
 * lateness, and staff's decision on it — the same shape as
 * Admin\RegistrationController's approve/reject, moved into a service because
 * two different controllers (participant, trainer/coordinator/admin) act on
 * the same rows.
 *
 * `status` on the attendance row is never touched here — it stays the door's
 * timestamp-derived truth. Approval only ever sets excused_at/excuse_reason/
 * excused_by (D-106); those columns are additive and are not read by any rate
 * calculation, because D-26 (how an excused session should weigh into the
 * certificate issuance rate) is still open.
 *
 * @see D-106, D-26 · PRD §9.9
 */
final class AttendanceExceptionRequester
{
    /** Same floor as a manual attendance edit (BR-10) — a real reason, not a character. */
    public const MIN_REASON_LENGTH = AttendanceRecorder::MIN_EDIT_REASON_LENGTH;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InAppNotifier $notifier,
    ) {}

    /**
     * @throws AttendanceException
     */
    public function request(
        User $user,
        Attendance $attendance,
        AttendanceExceptionType $type,
        string $reason,
    ): AttendanceExceptionRequest {
        $trimmed = trim($reason);

        if (mb_strlen($trimmed) < self::MIN_REASON_LENGTH) {
            throw AttendanceException::exceptionReasonTooShort(self::MIN_REASON_LENGTH);
        }

        // Re-checked here, not only in the policy (art. 5): a request is only
        // ever about the requester's own record. Unreachable through the
        // application — the policy already refuses this — so a mismatch here
        // is an invariant violation, not a business refusal to translate.
        if ((string) $attendance->getAttribute('user_id') !== (string) $user->getKey()) {
            throw new \RuntimeException('Attendance '.$attendance->getKey().' does not belong to '.$user->getKey().'.');
        }

        if (! $this->typeMatchesRecord($attendance, $type)) {
            throw AttendanceException::exceptionTypeMismatch();
        }

        $created = DB::transaction(function () use ($user, $attendance, $type, $trimmed): AttendanceExceptionRequest {
            $stillPending = AttendanceExceptionRequest::query()
                ->where('attendance_id', $attendance->getKey())
                ->pending()
                ->lockForUpdate()
                ->exists();

            if ($stillPending) {
                throw AttendanceException::exceptionAlreadyPending();
            }

            $request = new AttendanceExceptionRequest;
            $request->setAttribute($request->getKeyName(), (string) Str::uuid());
            $request->setAttribute('attendance_id', $attendance->getKey());
            $request->setAttribute('user_id', $user->getKey());
            $request->setAttribute('type', $type);
            $request->setAttribute('reason', $trimmed);
            $request->setAttribute('status', AttendanceExceptionStatus::Pending);

            $this->audit->log(
                action: AuditLogger::ATTENDANCE_EXCEPTION_REQUESTED,
                entity: $request,
                before: null,
                after: $this->snapshot($request),
                actor: $user,
            );

            $request->save();

            return $request;
        });

        $sessionTitle = $this->sessionTitle($attendance);

        // Staff sees it on the pending queue already; this is a courtesy
        // pointer to it, not the only way they would find out (PRD §9.16).
        $this->notifier->notify(
            $this->staffIds($attendance),
            'attendance_exception_requested',
            (string) __('notifications.types.attendance_exception_requested.title'),
            (string) __('notifications.types.attendance_exception_requested.body', ['name' => $this->requesterName($user)]),
            route('trainer.attendance'),
        );

        $this->notifier->notify(
            [(string) $user->getKey()],
            'attendance_exception_requested_confirmation',
            (string) __('notifications.types.attendance_exception_requested_confirmation.title'),
            (string) __('notifications.types.attendance_exception_requested_confirmation.body'),
            route('attendance.index'),
        );

        AttendanceExceptionRequested::dispatch($user, $sessionTitle, $type);

        return $created;
    }

    /**
     * @throws AttendanceException
     */
    public function approve(User $actor, AttendanceExceptionRequest $request): AttendanceExceptionRequest
    {
        /** @var Attendance $attendance */
        $attendance = $request->attendance()->firstOrFail();
        $recipient = $this->recipient($request);
        $sessionTitle = $this->sessionTitle($attendance);
        $type = $request->getAttribute('type');

        DB::transaction(function () use ($actor, $request, $attendance): void {
            $locked = AttendanceExceptionRequest::query()
                ->whereKey($request->getKey())
                ->pending()
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw AttendanceException::exceptionAlreadyDecided();
            }

            $now = Clock::now();
            $before = $this->snapshot($locked);

            $locked->setAttribute('status', AttendanceExceptionStatus::Approved);
            $locked->setAttribute('decided_by', $actor->getKey());
            $locked->setAttribute('decided_at', $now);

            $attendance->setAttribute('excused_at', $now);
            $attendance->setAttribute('excuse_reason', $locked->getAttribute('reason'));
            $attendance->setAttribute('excused_by', $actor->getKey());

            $this->audit->log(
                action: AuditLogger::ATTENDANCE_EXCEPTION_APPROVED,
                entity: $locked,
                before: $before,
                after: $this->snapshot($locked),
                actor: $actor,
            );

            $attendance->save();
            $locked->save();
        });

        $this->notifier->notify(
            [(string) $recipient->getKey()],
            'attendance_exception_approved',
            (string) __('notifications.types.attendance_exception_approved.title'),
            (string) __('notifications.types.attendance_exception_approved.body', ['session' => $sessionTitle]),
            route('attendance.index'),
        );

        AttendanceExceptionApproved::dispatch($recipient, $sessionTitle, $type);

        return $request->refresh();
    }

    /**
     * @throws AttendanceException
     */
    public function reject(User $actor, AttendanceExceptionRequest $request, string $reason): AttendanceExceptionRequest
    {
        $trimmed = trim($reason);

        if (mb_strlen($trimmed) < self::MIN_REASON_LENGTH) {
            throw AttendanceException::exceptionReasonTooShort(self::MIN_REASON_LENGTH);
        }

        $recipient = $this->recipient($request);
        $sessionTitle = $this->sessionTitle($request->attendance()->firstOrFail());
        $type = $request->getAttribute('type');

        DB::transaction(function () use ($actor, $request, $trimmed): void {
            $locked = AttendanceExceptionRequest::query()
                ->whereKey($request->getKey())
                ->pending()
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw AttendanceException::exceptionAlreadyDecided();
            }

            $before = $this->snapshot($locked);

            $locked->setAttribute('status', AttendanceExceptionStatus::Rejected);
            $locked->setAttribute('decision_reason', $trimmed);
            $locked->setAttribute('decided_by', $actor->getKey());
            $locked->setAttribute('decided_at', Clock::now());

            $this->audit->log(
                action: AuditLogger::ATTENDANCE_EXCEPTION_REJECTED,
                entity: $locked,
                before: $before,
                after: $this->snapshot($locked),
                actor: $actor,
            );

            $locked->save();
        });

        $this->notifier->notify(
            [(string) $recipient->getKey()],
            'attendance_exception_rejected',
            (string) __('notifications.types.attendance_exception_rejected.title'),
            (string) __('notifications.types.attendance_exception_rejected.body', ['reason' => $trimmed]),
            route('attendance.index'),
        );

        AttendanceExceptionRejected::dispatch($recipient, $sessionTitle, $type, $trimmed);

        return $request->refresh();
    }

    private function typeMatchesRecord(Attendance $attendance, AttendanceExceptionType $type): bool
    {
        if ($attendance->isExcused()) {
            return false;
        }

        $status = $attendance->getAttribute('status');

        return match ($type) {
            AttendanceExceptionType::Absence => $status === AttendanceStatus::Absent,
            AttendanceExceptionType::Lateness => $status === AttendanceStatus::Late,
        };
    }

    /**
     * Everyone with attendance abilities on this record's cohort — a
     * coordinator, a trainer or an admin (D-105) — so a request never waits
     * on exactly one person being online.
     *
     * @return list<string>
     */
    private function staffIds(Attendance $attendance): array
    {
        $cohortId = (string) $this->sessionOf($attendance)->getAttribute('cohort_id');

        $ids = Enrollment::query()
            ->where('cohort_id', $cohortId)
            ->whereIn('role_in_cohort', [EnrollmentRole::Trainer->value, EnrollmentRole::Coordinator->value])
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $adminIds = User::query()
            ->where('role', UserRole::Admin->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$adminIds]));
    }

    private function sessionTitle(Attendance $attendance): string
    {
        $session = $this->sessionOf($attendance);

        return (string) ($session->getAttribute('topic') ?? $session->getAttribute('title') ?? '');
    }

    private function sessionOf(Attendance $attendance): Session
    {
        /** @var Session $session */
        $session = $attendance->relationLoaded('session') ? $attendance->session : $attendance->session()->firstOrFail();

        return $session;
    }

    private function requesterName(User $user): string
    {
        $name = $user->profile?->getAttribute('full_name_ar');

        return is_string($name) && $name !== '' ? $name : (string) $user->getAttribute('email');
    }

    /**
     * The account a decision reaches. `attendance_exception_requests.user_id`
     * is a constrained, non-nullable foreign key, so a request without an
     * account is unreachable through the application.
     */
    private function recipient(AttendanceExceptionRequest $request): User
    {
        $user = $request->user;

        if ($user === null) {
            throw new \RuntimeException('Exception request '.$request->getKey().' has no account attached.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AttendanceExceptionRequest $request): array
    {
        return $this->audit->snapshot($request, [
            'id',
            'attendance_id',
            'user_id',
            'type',
            'reason',
            'status',
            'decision_reason',
            'decided_by',
        ]);
    }
}
