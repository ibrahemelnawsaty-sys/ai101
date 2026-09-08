<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EvaluationEntity;
use App\Models\Assignment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Grades and trainer feedback.
 *
 * Recording a grade and amending one are trainer-only, deliberately: the
 * permission matrix in PRD §4.2 marks both as "no" for an admin. That reading
 * is disputed and is recorded as an OPEN decision (D-16 in DECISIONS.md); until
 * it is settled this policy follows the source document literally rather than
 * widening a permission on a guess (CONSTITUTION Art. 4).
 *
 * A participant sees only their own grades — never a classmate's and never the
 * cohort average (PRD §9.15.4).
 *
 * @see BR-12, BR-13, BR-14, BR-22, BR-23 · PRD §4.2, §9.15 · CONSTITUTION Art. 22
 */
final class EvaluationPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Evaluation $evaluation): bool
    {
        if ($this->owns($user, (string) $evaluation->user_id)) {
            return true;
        }

        return $this->staffOf($user, $this->cohortIdOf($evaluation));
    }

    /** BR-13: no grade without feedback — the length rule lives in the FormRequest. */
    public function create(User $user, Evaluation $evaluation): bool
    {
        return $this->trainerOf($user, $this->cohortIdOf($evaluation)) && $this->writesAllowed();
    }

    /** BR-14: amending a recorded grade needs a written reason and is audited. */
    public function update(User $user, Evaluation $evaluation): bool
    {
        return $this->trainerOf($user, $this->cohortIdOf($evaluation)) && $this->writesAllowed();
    }

    public function delete(User $user, Evaluation $evaluation): bool
    {
        return false;
    }

    public function forceDelete(User $user, Evaluation $evaluation): bool
    {
        return false;
    }

    /**
     * An evaluation points at a SUBMISSION, not at the task itself
     * (PROJECT-CONTRACT §4: `evaluations.entity_id` is the submission id), and
     * the table carries no `cohort_id` column at all. The cohort is therefore
     * reached in two hops: submission -> assignment|final_project -> cohort.
     *
     * @see BR-22, BR-23 · PRD §7.5
     */
    private function cohortIdOf(Evaluation $evaluation): ?string
    {
        // `evaluations.entity_id` is char(36) NOT NULL (PROJECT-CONTRACT §4),
        // so a loaded evaluation always carries one; there is no null case to
        // guard here.
        $entityId = $evaluation->entity_id;

        if ($evaluation->entity_type === EvaluationEntity::FinalProject) {
            $finalProjectId = ProjectSubmission::query()
                ->whereKey($entityId)
                ->value('final_project_id');

            $cohortId = $finalProjectId === null
                ? null
                : FinalProject::query()->whereKey($finalProjectId)->value('cohort_id');
        } else {
            $assignmentId = Submission::query()
                ->whereKey($entityId)
                ->value('assignment_id');

            $cohortId = $assignmentId === null
                ? null
                : Assignment::query()->whereKey($assignmentId)->value('cohort_id');
        }

        return is_string($cohortId) && $cohortId !== '' ? $cohortId : null;
    }
}
