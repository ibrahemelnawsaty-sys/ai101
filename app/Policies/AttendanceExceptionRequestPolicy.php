<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attendance;
use App\Models\AttendanceExceptionRequest;
use App\Models\Session;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Excuse requests for an absence or an unexcused lateness (D-106).
 *
 * Mirrors AttendancePolicy exactly: a participant acts only on their own
 * record, and a coordinator, trainer or admin acts on any record in a cohort
 * their attendance abilities already cover (BR-22, BR-23).
 *
 * @see D-106 · CONSTITUTION Art. 22
 */
final class AttendanceExceptionRequestPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, AttendanceExceptionRequest $request): bool
    {
        if ($this->owns($user, (string) $request->user_id)) {
            return true;
        }

        return $this->attendanceStaffOf($user, $this->cohortIdOf($request));
    }

    /** Only the record's own owner may ask to be excused for it (BR-22). */
    public function create(User $user, Attendance $attendance): bool
    {
        return $this->writesAllowed()
            && $this->owns($user, (string) $attendance->getAttribute('user_id'));
    }

    /** Decide — coordinator, trainer or admin of the cohort (D-105). */
    public function decide(User $user, AttendanceExceptionRequest $request): bool
    {
        return $this->writesAllowed()
            && $this->attendanceStaffOf($user, $this->cohortIdOf($request));
    }

    private function cohortIdOf(AttendanceExceptionRequest $request): ?string
    {
        $attendance = $request->relationLoaded('attendance')
            ? $request->attendance
            : Attendance::query()->find($request->attendance_id);

        if (! $attendance instanceof Attendance) {
            return null;
        }

        $session = $attendance->relationLoaded('session')
            ? $attendance->session
            : Session::query()->find($attendance->session_id);

        return $session === null ? null : (string) $session->cohort_id;
    }
}
