<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Final-project submissions. Same shape as assignment submissions: own work
 * only for a participant, own cohorts only for a trainer, versions preserved.
 *
 * @see BR-19, BR-22, BR-23 · PRD §9.14 · CONSTITUTION Art. 22
 */
final class ProjectSubmissionPolicy
{
    use InteractsWithScope;

    public function view(User $user, ProjectSubmission $submission): bool
    {
        if ($this->owns($user, (string) $submission->user_id)) {
            return true;
        }

        return $this->staffOf($user, $this->cohortIdOf($submission));
    }

    public function download(User $user, ProjectSubmission $submission): bool
    {
        return $this->view($user, $submission);
    }

    public function update(User $user, ProjectSubmission $submission): bool
    {
        return $this->owns($user, (string) $submission->user_id) && $this->writesAllowed();
    }

    public function evaluate(User $user, ProjectSubmission $submission): bool
    {
        return $this->trainerOf($user, $this->cohortIdOf($submission)) && $this->writesAllowed();
    }

    public function delete(User $user, ProjectSubmission $submission): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProjectSubmission $submission): bool
    {
        return false;
    }

    private function cohortIdOf(ProjectSubmission $submission): ?string
    {
        $project = $submission->relationLoaded('finalProject')
            ? $submission->finalProject
            : FinalProject::query()->find($submission->final_project_id);

        return $project === null ? null : (string) $project->cohort_id;
    }
}
