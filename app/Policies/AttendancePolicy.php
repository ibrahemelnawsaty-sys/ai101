<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attendance;
use App\Models\Session;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Attendance records.
 *
 * Only a participant of the session's own cohort may check in or out, and only
 * for themselves — there is no endpoint that marks someone else present. The
 * time window itself is decided by `AttendanceWindow`, not here: this policy
 * answers "may this person act on this session at all".
 *
 * @see BR-01..BR-07, BR-10, BR-22, BR-23 · PRD §9.9 · CONSTITUTION Art. 22
 */
final class AttendancePolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    /** Own record, the cohort's trainer, or an admin — nobody else (BR-22). */
    public function view(User $user, Attendance $attendance): bool
    {
        if ($this->owns($user, (string) $attendance->user_id)) {
            return true;
        }

        return $this->staffOf($user, $this->cohortIdOf($attendance));
    }

    /**
     * Whether this account may act on this session at all. A CANCELLED session
     * is deliberately NOT refused here.
     *
     * PRD §9.9.8 gives the cancelled session its own participant-facing message
     * - "this session is cancelled and attendance cannot be recorded" - in the
     * same table as "the check-in window has closed". Those are business-rule
     * refusals: the server answers 302 back with the message in the error bag.
     * A 403 says "this request was not yours to make", carries a different
     * screen and cannot show that sentence. AttendanceRecorder::guardSession()
     * raises AttendanceException::sessionCancelled() for it (BR-07).
     */
    public function checkIn(User $user, Session $session): bool
    {
        return $this->writesAllowed()
            && $this->participantOf($user, (string) $session->cohort_id);
    }

    public function checkOut(User $user, Session $session): bool
    {
        return $this->checkIn($user, $session);
    }

    /** Manual correction — trainers of the cohort and admins, with a reason (BR-10). */
    public function update(User $user, Attendance $attendance): bool
    {
        return $this->writesAllowed() && $this->staffOf($user, $this->cohortIdOf($attendance));
    }

    public function bulkMark(User $user, Session $session): bool
    {
        return $this->writesAllowed() && $this->staffOf($user, (string) $session->cohort_id);
    }

    public function export(User $user, Session $session): bool
    {
        return $this->staffOf($user, (string) $session->cohort_id);
    }

    /** Attendance rows are never destroyed (CONSTITUTION Art. 13 §11). */
    public function delete(User $user, Attendance $attendance): bool
    {
        return false;
    }

    public function forceDelete(User $user, Attendance $attendance): bool
    {
        return false;
    }

    private function cohortIdOf(Attendance $attendance): ?string
    {
        $session = $attendance->relationLoaded('session')
            ? $attendance->session
            : Session::query()->find($attendance->session_id);

        return $session === null ? null : (string) $session->cohort_id;
    }
}
