<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use App\Services\Permissions\RoleResolver;
use App\Support\ImpersonationContext;

/**
 * Shared vocabulary for every policy: who is an admin, who owns a row, which
 * cohort a caller may reach, and the blanket refusal of writes while an account
 * preview is running.
 *
 * @see BR-22, BR-23, BR-33 · PRD §4.3 · CONSTITUTION Art. 22, Art. 23
 */
trait InteractsWithScope
{
    public function __construct(protected readonly RoleResolver $roles) {}

    /** Every write is refused for the whole duration of a preview (BR-33). */
    protected function writesAllowed(): bool
    {
        return ! ImpersonationContext::isActive();
    }

    protected function admin(User $user): bool
    {
        return $this->roles->isActive($user) && $this->roles->isAdmin($user);
    }

    protected function owns(User $user, ?string $ownerId): bool
    {
        return $ownerId !== null
            && $this->roles->isActive($user)
            && (string) $user->getKey() === (string) $ownerId;
    }

    protected function trainerOf(User $user, ?string $cohortId): bool
    {
        return $this->roles->isTrainerOf($user, $cohortId);
    }

    protected function coordinatorOf(User $user, ?string $cohortId): bool
    {
        return $this->roles->isCoordinatorOf($user, $cohortId);
    }

    protected function participantOf(User $user, ?string $cohortId): bool
    {
        return $this->roles->isParticipantOf($user, $cohortId);
    }

    /** Admin everywhere, trainer inside their own cohorts (PRD §4.2). */
    protected function staffOf(User $user, ?string $cohortId): bool
    {
        return $this->admin($user) || $this->trainerOf($user, $cohortId);
    }

    /**
     * Attendance authority only: everything staffOf() already grants, plus a
     * coordinator assigned to the cohort — the one ability their role exists
     * for. Never used for session, resource or cohort management, which stay
     * staffOf()-gated: a coordinator is not a trainer.
     */
    protected function attendanceStaffOf(User $user, ?string $cohortId): bool
    {
        return $this->staffOf($user, $cohortId) || $this->coordinatorOf($user, $cohortId);
    }

    /**
     * Session schedule authority: admin everywhere, or a coordinator assigned
     * to the cohort. A trainer used to sit here too (D-105) — the owner asked
     * that the trainer's tab become read-only, so only the coordinator (who
     * runs the room) and the admin (who runs everything) write the schedule,
     * the location and the meeting link, avoiding two roles maintaining the
     * same link (D-109 amends D-105 to this narrower scope explicitly).
     */
    protected function sessionManagerOf(User $user, ?string $cohortId): bool
    {
        return $this->admin($user) || $this->coordinatorOf($user, $cohortId);
    }

    /** Anyone with a legitimate reason to read this cohort's data. */
    protected function reaches(User $user, ?string $cohortId): bool
    {
        return $this->roles->canReachCohort($user, $cohortId);
    }
}
