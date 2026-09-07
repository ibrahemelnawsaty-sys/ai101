<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Assignments. The trainer decides what exists, what is mandatory, what it is
 * worth and when it is due (BR-17); an admin may do the same for any cohort.
 * A draft assignment is invisible to participants until it is published.
 *
 * @see BR-17, BR-18, BR-22, BR-23 · PRD §9.11 · CONSTITUTION Art. 22
 */
final class AssignmentPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Assignment $assignment): bool
    {
        $cohortId = (string) $assignment->cohort_id;

        if ($this->staffOf($user, $cohortId)) {
            return true;
        }

        return $assignment->status === AssignmentStatus::Published
            && $this->participantOf($user, $cohortId);
    }

    public function create(User $user, Assignment $assignment): bool
    {
        return $this->staffOf($user, (string) $assignment->cohort_id) && $this->writesAllowed();
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $this->staffOf($user, (string) $assignment->cohort_id) && $this->writesAllowed();
    }

    public function publish(User $user, Assignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }

    /** Submitting is a participant action, and only for a published task. */
    public function submit(User $user, Assignment $assignment): bool
    {
        return $this->writesAllowed()
            && $assignment->status === AssignmentStatus::Published
            && $this->participantOf($user, (string) $assignment->cohort_id);
    }

    public function viewSubmissions(User $user, Assignment $assignment): bool
    {
        return $this->staffOf($user, (string) $assignment->cohort_id);
    }

    public function downloadAll(User $user, Assignment $assignment): bool
    {
        return $this->staffOf($user, (string) $assignment->cohort_id);
    }

    public function remind(User $user, Assignment $assignment): bool
    {
        return $this->staffOf($user, (string) $assignment->cohort_id) && $this->writesAllowed();
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return $this->staffOf($user, (string) $assignment->cohort_id) && $this->writesAllowed();
    }

    public function forceDelete(User $user, Assignment $assignment): bool
    {
        return false;
    }
}
