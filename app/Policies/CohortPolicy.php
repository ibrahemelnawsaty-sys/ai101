<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cohort;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Cohorts. Creating one, setting its capacity, its pass score, its minimum
 * attendance rate and its trainers is admin-only. Reading one requires an
 * actual link to it — a trainer assignment or an enrollment (BR-23).
 *
 * @see BR-22, BR-23 · PRD §4.2, §9.18 · CONSTITUTION Art. 22
 */
final class CohortPolicy
{
    use InteractsWithScope;

    /**
     * Making a cohort the one the participant screens show. The same list the
     * request validates against and ResolvesActiveCohort reads — not view(),
     * whose narrower reach would refuse options the switcher offers (D-75).
     * Whether that list should be narrower is open (D-76).
     */
    public function switchTo(User $user, Cohort $cohort): bool
    {
        return $this->roles->isActive($user)
            && $this->writesAllowed()
            && in_array((string) $cohort->getKey(), $user->accessibleCohortIds(), true);
    }

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Cohort $cohort): bool
    {
        return $this->reaches($user, (string) $cohort->getKey());
    }

    /** Trainer-facing operational screens for one cohort. */
    public function operate(User $user, Cohort $cohort): bool
    {
        return $this->staffOf($user, (string) $cohort->getKey());
    }

    public function create(User $user): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function update(User $user, Cohort $cohort): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function assignTrainer(User $user, Cohort $cohort): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function manageRegistrations(User $user, Cohort $cohort): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function viewReports(User $user, Cohort $cohort): bool
    {
        return $this->staffOf($user, (string) $cohort->getKey());
    }

    public function export(User $user, Cohort $cohort): bool
    {
        return $this->staffOf($user, (string) $cohort->getKey());
    }

    public function delete(User $user, Cohort $cohort): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function forceDelete(User $user, Cohort $cohort): bool
    {
        return false;
    }
}
