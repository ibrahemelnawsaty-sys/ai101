<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinalProject;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The final project, and the lock in front of it.
 *
 * BR-15/BR-16 are the point of this file: before a trainer unlocks the tab, a
 * participant gets 403 and the project's description, files and criteria are
 * never loaded, never serialised and never reach the browser. Hiding the tab in
 * the sidebar is not the control — this is.
 *
 * @see BR-15, BR-16, BR-22, BR-23 · PRD §9.14 · CONSTITUTION Art. 22
 */
final class FinalProjectPolicy
{
    use InteractsWithScope;

    /** Staff always; a participant only once the project is unlocked (BR-15). */
    public function view(User $user, FinalProject $project): bool
    {
        $cohortId = (string) $project->cohort_id;

        if ($this->staffOf($user, $cohortId)) {
            return true;
        }

        return (bool) $project->is_unlocked && $this->participantOf($user, $cohortId);
    }

    public function unlock(User $user, FinalProject $project): bool
    {
        return $this->staffOf($user, (string) $project->cohort_id) && $this->writesAllowed();
    }

    public function update(User $user, FinalProject $project): bool
    {
        return $this->staffOf($user, (string) $project->cohort_id) && $this->writesAllowed();
    }

    public function submit(User $user, FinalProject $project): bool
    {
        return $this->writesAllowed()
            && (bool) $project->is_unlocked
            && $this->participantOf($user, (string) $project->cohort_id);
    }

    public function delete(User $user, FinalProject $project): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }
}
