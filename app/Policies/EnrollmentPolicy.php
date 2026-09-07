<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Enrollments: the row that decides, per cohort, whether a person is a trainer
 * or a participant (PRD §4.4). Approving or rejecting a registration request is
 * admin-only; a trainer may read the enrollments of their own cohorts.
 *
 * @see BR-22, BR-23 · PRD §4.4, §9.18 · CONSTITUTION Art. 22
 */
final class EnrollmentPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        return $this->owns($user, (string) $enrollment->user_id)
            || $this->staffOf($user, (string) $enrollment->cohort_id);
    }

    public function approve(User $user, Enrollment $enrollment): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function reject(User $user, Enrollment $enrollment): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function update(User $user, Enrollment $enrollment): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function delete(User $user, Enrollment $enrollment): bool
    {
        return false;
    }
}
