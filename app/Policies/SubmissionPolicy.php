<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Assignment submissions and their files.
 *
 * A participant reaches their own submissions and nothing else — changing the
 * id in the URL must end in 403, not in someone else's work (BR-22). Trainers
 * reach the submissions of their own cohorts only (BR-23).
 *
 * Earlier versions are kept, never replaced and never deleted (BR-19).
 *
 * @see BR-19, BR-22, BR-23 · PRD §9.11 · CONSTITUTION Art. 22
 */
final class SubmissionPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Submission $submission): bool
    {
        if ($this->owns($user, (string) $submission->user_id)) {
            return true;
        }

        return $this->staffOf($user, $this->cohortIdOf($submission));
    }

    public function download(User $user, Submission $submission): bool
    {
        return $this->view($user, $submission);
    }

    /** Re-submission: the owner, before the deadline rules decide the rest. */
    public function update(User $user, Submission $submission): bool
    {
        return $this->owns($user, (string) $submission->user_id) && $this->writesAllowed();
    }

    public function evaluate(User $user, Submission $submission): bool
    {
        return $this->trainerOf($user, $this->cohortIdOf($submission)) && $this->writesAllowed();
    }

    /** Submissions are evidence: they are never destroyed (BR-19). */
    public function delete(User $user, Submission $submission): bool
    {
        return false;
    }

    public function forceDelete(User $user, Submission $submission): bool
    {
        return false;
    }

    private function cohortIdOf(Submission $submission): ?string
    {
        $assignment = $submission->relationLoaded('assignment')
            ? $submission->assignment
            : Assignment::query()->find($submission->assignment_id);

        return $assignment === null ? null : (string) $assignment->cohort_id;
    }
}
