<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SessionStatus;
use App\Models\Session;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Training sessions: the schedule, the Zoom links and the recordings.
 *
 * The Zoom link is treated as a secret. This policy only answers "may this
 * person ever be told the link"; *when* it may be told is a time window and
 * lives in `App\Services\Attendance\LiveWindow` (BR-24). Both must agree
 * before a controller hands the link out.
 *
 * @see BR-22, BR-23, BR-24 · PRD §4.2, §9.8, §9.10 · CONSTITUTION Art. 22
 */
final class SessionPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Session $session): bool
    {
        return $this->reaches($user, (string) $session->cohort_id);
    }

    /**
     * Membership half of the Zoom-link decision. The window half is checked by
     * `LiveWindow` in the controller — never in the view (BR-24).
     */
    public function revealJoinLink(User $user, Session $session): bool
    {
        return $this->reaches($user, (string) $session->cohort_id)
            && $session->status !== SessionStatus::Cancelled;
    }

    public function viewRecording(User $user, Session $session): bool
    {
        return $this->reaches($user, (string) $session->cohort_id)
            && $session->status === SessionStatus::Completed;
    }

    public function create(User $user, Session $session): bool
    {
        return $this->staffOf($user, (string) $session->cohort_id) && $this->writesAllowed();
    }

    public function update(User $user, Session $session): bool
    {
        return $this->staffOf($user, (string) $session->cohort_id) && $this->writesAllowed();
    }

    public function cancel(User $user, Session $session): bool
    {
        return $this->staffOf($user, (string) $session->cohort_id) && $this->writesAllowed();
    }

    public function manageAttendance(User $user, Session $session): bool
    {
        return $this->staffOf($user, (string) $session->cohort_id) && $this->writesAllowed();
    }

    public function delete(User $user, Session $session): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function forceDelete(User $user, Session $session): bool
    {
        return false;
    }
}
