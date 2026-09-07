<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\Session;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records check-in and check-out. Every rule below is enforced here, on the
 * server, on every request — the interface only mirrors the outcome.
 *
 *  · a cancelled session refuses every registration
 *  · the participant must hold an active enrolment in the session's cohort
 *  · BR-01/BR-04 windows are checked against server time only
 *  · BR-05 no check-out without a prior check-in for the same session
 *  · BR-06 duplicates are impossible: the unique index (session_id, user_id) is
 *    the guard, and its violation is converted into a domain error — a
 *    read-then-write check would lose the race between two concurrent requests
 *  · ip_address and user_agent are stored for auditing only
 *
 * @see BR-01, BR-02, BR-03, BR-04, BR-05, BR-06, BR-07, BR-10 · PRD §9.9
 */
final class AttendanceRecorder
{
    /** BR-10 — a manual edit needs a real reason, not a character. */
    public const MIN_EDIT_REASON_LENGTH = 10;

    /** MySQL SQLSTATE for an integrity constraint violation. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /** MySQL driver error for a duplicate key. */
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    /** SQLite driver error for a constraint violation, used by the test suite. */
    private const SQLITE_CONSTRAINT = 19;

    private const IP_MAX_LENGTH = 45;

    private const USER_AGENT_MAX_LENGTH = 500;

    public function __construct(
        private readonly AttendanceWindow $window,
        private readonly AuditLogger $audit,
        private readonly RiyadhFormatter $formatter,
    ) {}

    /**
     * BR-01, BR-02, BR-03, BR-06.
     *
     * @throws AttendanceException
     */
    public function checkIn(
        User $user,
        Session $session,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Attendance {
        $at = Clock::now();

        $this->guardSession($user, $session, AuditLogger::ATTENDANCE_CHECK_IN);
        $this->guardEnrolment($user, $session, AuditLogger::ATTENDANCE_CHECK_IN);
        $this->guardCheckInWindow($user, $session, $at);

        $status = $this->window->classify($session, $at);

        try {
            return DB::transaction(function () use ($user, $session, $at, $status, $ipAddress, $userAgent): Attendance {
                $attendance = new Attendance();

                // The key is assigned up front so the audit entry can name the
                // row before it exists (CONSTITUTION art. 8).
                $attendance->setAttribute($attendance->getKeyName(), (string) Str::uuid());
                $attendance->setAttribute('session_id', $session->getKey());
                $attendance->setAttribute('user_id', $user->getKey());
                $attendance->setAttribute('check_in_at', $at);
                $attendance->setAttribute('check_out_at', null);
                $attendance->setAttribute('status', $status);
                $attendance->setAttribute('is_manual', false);
                $attendance->setAttribute('edited_by', null);
                $attendance->setAttribute('edit_reason', null);
                $attendance->setAttribute('ip_address', $this->trim($ipAddress, self::IP_MAX_LENGTH));
                $attendance->setAttribute('user_agent', $this->trim($userAgent, self::USER_AGENT_MAX_LENGTH));

                $this->audit->log(
                    action: AuditLogger::ATTENDANCE_CHECK_IN,
                    entity: $attendance,
                    before: null,
                    after: $this->audit->snapshot($attendance, $this->auditedColumns()),
                    actor: $user,
                );

                $attendance->save();

                return $attendance;
            });
        } catch (QueryException $e) {
            throw $this->duplicateCheckIn($user, $session, $e);
        }
    }

    /**
     * BR-04, BR-05.
     *
     * @throws AttendanceException
     */
    public function checkOut(
        User $user,
        Session $session,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Attendance {
        $at = Clock::now();

        $this->guardSession($user, $session, AuditLogger::ATTENDANCE_CHECK_OUT);
        $this->guardEnrolment($user, $session, AuditLogger::ATTENDANCE_CHECK_OUT);

        if (! $this->window->canCheckOut($session, $at)) {
            $failure = $at->lessThan($this->window->checkOutOpensAt($session))
                ? AttendanceException::checkOutNotOpen()
                : AttendanceException::checkOutClosed();

            $this->audit->reject(AuditLogger::ATTENDANCE_CHECK_OUT, $session, $failure, $user);

            throw $failure;
        }

        $attendance = $this->findRecord($user, $session);

        // BR-05 — a row created by the reconciler (absent) or by a trainer
        // (excused) carries no check_in_at and therefore does not qualify.
        if ($attendance === null || $attendance->getAttribute('check_in_at') === null) {
            $failure = AttendanceException::checkOutWithoutCheckIn();
            $this->audit->reject(AuditLogger::ATTENDANCE_CHECK_OUT, $session, $failure, $user);

            throw $failure;
        }

        if ($attendance->getAttribute('check_out_at') !== null) {
            $failure = AttendanceException::alreadyCheckedOut();
            $this->audit->reject(AuditLogger::ATTENDANCE_CHECK_OUT, $attendance, $failure, $user);

            throw $failure;
        }

        return DB::transaction(function () use ($attendance, $user, $at, $ipAddress, $userAgent): Attendance {
            $before = $this->audit->snapshot($attendance, $this->auditedColumns());

            $attendance->setAttribute('check_out_at', $at);

            if ($attendance->getAttribute('ip_address') === null) {
                $attendance->setAttribute('ip_address', $this->trim($ipAddress, self::IP_MAX_LENGTH));
            }

            if ($attendance->getAttribute('user_agent') === null) {
                $attendance->setAttribute('user_agent', $this->trim($userAgent, self::USER_AGENT_MAX_LENGTH));
            }

            $this->audit->log(
                action: AuditLogger::ATTENDANCE_CHECK_OUT,
                entity: $attendance,
                before: $before,
                after: $this->audit->snapshot($attendance, $this->auditedColumns()),
                actor: $user,
            );

            $attendance->save();

            return $attendance;
        });
    }

    /**
     * BR-10 — a trainer or administrator may correct a record, but only with a
     * written reason of at least ten characters, and always into the trail.
     *
     * @throws AttendanceException
     */
    public function manualOverride(
        User $actor,
        Attendance $attendance,
        AttendanceStatus $status,
        string $reason,
    ): Attendance {
        $trimmed = trim($reason);

        if (mb_strlen($trimmed) < self::MIN_EDIT_REASON_LENGTH) {
            $failure = AttendanceException::reasonTooShort(self::MIN_EDIT_REASON_LENGTH);
            $this->audit->reject(AuditLogger::ATTENDANCE_MANUAL_EDIT, $attendance, $failure, $actor);

            throw $failure;
        }

        return DB::transaction(function () use ($actor, $attendance, $status, $trimmed): Attendance {
            $before = $this->audit->snapshot($attendance, $this->auditedColumns());

            $attendance->setAttribute('status', $status);
            $attendance->setAttribute('is_manual', true);
            $attendance->setAttribute('edited_by', $actor->getKey());
            $attendance->setAttribute('edit_reason', $trimmed);

            $this->audit->log(
                action: AuditLogger::ATTENDANCE_MANUAL_EDIT,
                entity: $attendance,
                before: $before,
                after: $this->audit->snapshot($attendance, $this->auditedColumns()),
                actor: $actor,
            );

            $attendance->save();

            return $attendance;
        });
    }

    public function findRecord(User $user, Session $session): ?Attendance
    {
        return Attendance::query()
            ->where('session_id', $session->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * @throws AttendanceException
     */
    private function guardSession(User $user, Session $session, string $action): void
    {
        if ($this->window->isCancelled($session)) {
            $failure = AttendanceException::sessionCancelled();
            $this->audit->reject($action, $session, $failure, $user);

            throw $failure;
        }
    }

    /**
     * A participant may only register attendance for a cohort they belong to.
     *
     * @throws AttendanceException
     */
    private function guardEnrolment(User $user, Session $session, string $action): void
    {
        $enrolled = Enrollment::query()
            ->where('cohort_id', $session->getAttribute('cohort_id'))
            ->where('user_id', $user->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->exists();

        if (! $enrolled) {
            $failure = AttendanceException::notEnrolled();
            $this->audit->reject($action, $session, $failure, $user);

            throw $failure;
        }
    }

    /**
     * @throws AttendanceException
     */
    private function guardCheckInWindow(User $user, Session $session, CarbonImmutable $at): void
    {
        if ($this->window->canCheckIn($session, $at)) {
            return;
        }

        if ($at->lessThan($this->window->checkInOpensAt($session))) {
            $failure = AttendanceException::checkInNotOpen(
                $this->formatter->duration($this->window->secondsUntilCheckInOpens($session, $at)),
            );
        } else {
            $failure = AttendanceException::checkInClosed();
        }

        $this->audit->reject(AuditLogger::ATTENDANCE_CHECK_IN, $session, $failure, $user);

        throw $failure;
    }

    /**
     * BR-06 — the unique index is the authority. Its violation is translated
     * here, after reading which kind of row already occupies the slot.
     */
    private function duplicateCheckIn(User $user, Session $session, QueryException $e): AttendanceException
    {
        if (! $this->isUniqueViolation($e)) {
            throw $e;
        }

        $existing = $this->findRecord($user, $session);

        $failure = $existing !== null && $existing->getAttribute('check_in_at') !== null
            ? AttendanceException::alreadyCheckedIn($e)
            : AttendanceException::recordAlreadyExists($e);

        $this->audit->reject(AuditLogger::ATTENDANCE_CHECK_IN, $session, $failure, $user);

        return $failure;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        if ((string) $e->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
            return true;
        }

        $driverCode = $e->errorInfo[1] ?? null;

        return $driverCode === self::MYSQL_DUPLICATE_ENTRY || $driverCode === self::SQLITE_CONSTRAINT;
    }

    /**
     * @return list<string>
     */
    private function auditedColumns(): array
    {
        return [
            'id',
            'session_id',
            'user_id',
            'check_in_at',
            'check_out_at',
            'status',
            'is_manual',
            'edited_by',
            'edit_reason',
            'ip_address',
        ];
    }

    private function trim(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $length);
    }
}
