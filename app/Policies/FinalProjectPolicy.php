<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;
use App\Services\Cohorts\PrimaryCoordinator;

/**
 * The final project, and the lock in front of it.
 *
 * BR-15/BR-16 are the point of this file: before the project is published, a
 * participant gets 403 and the project's description, files and criteria are
 * never loaded, never serialised and never reach the browser. Hiding the tab in
 * the sidebar is not the control — this is.
 *
 * D-127 — publishing takes two people. The general supervisor makes the project
 * available (`update`, the settings save); only then may the cohort's PRIMARY
 * coordinator publish it (`publish`) or take it down again (`unpublish`). A
 * cohort with no primary coordinator cannot publish at all.
 *
 * @see BR-15, BR-16, BR-22, BR-23 · PRD §9.14 · D-124, D-127 · CONSTITUTION Art. 22
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

    /**
     * D-110, D-127 — the settings (the brief, the deadline, the ceiling, the
     * late policy) and making the project available are the general
     * supervisor's; a trainer's own screen keeps `view` and grading.
     */
    public function update(User $user, FinalProject $project): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    /**
     * D-127 — the coordinator's own final-project tab: the project's summary
     * and the publishing controls. Any coordinator of the cohort reads it; the
     * buttons answer to `publish`/`unpublish` alone.
     */
    public function coordinate(User $user, FinalProject $project): bool
    {
        return $this->admin($user) || $this->coordinatorOf($user, (string) $project->cohort_id);
    }

    /** D-127 — opening the project to the trainees: available first, then the primary coordinator. */
    public function publish(User $user, FinalProject $project): bool
    {
        return $this->writesAllowed()
            && (bool) $project->is_available
            && $this->isPrimaryCoordinator($user, $project);
    }

    /** D-127 — taking a published project down again: the primary coordinator. */
    public function unpublish(User $user, FinalProject $project): bool
    {
        return $this->writesAllowed()
            && (bool) $project->is_unlocked
            && $this->isPrimaryCoordinator($user, $project);
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

    /**
     * The cohort's primary coordinator (D-124), still an active account and
     * still coordinating the cohort — PrimaryCoordinator answers nobody for a
     * cohort without one, so such a cohort cannot publish (D-127).
     */
    private function isPrimaryCoordinator(User $user, FinalProject $project): bool
    {
        $cohort = Cohort::query()->find((string) $project->cohort_id);

        return $cohort instanceof Cohort
            && $this->roles->isActive($user)
            && app(PrimaryCoordinator::class)->idOf($cohort) === (string) $user->getKey();
    }
}
