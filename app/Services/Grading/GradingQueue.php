<?php

declare(strict_types=1);

namespace App\Services\Grading;

use App\Models\ProjectSubmission;
use App\Models\Submission;

/**
 * Which hand-in the trainer opens after «save and go to the next».
 *
 * The waiting hand-in is:
 *   · in the SAME assignment (or the same final project) as the one just
 *     marked — so the next one is always inside the cohort the policy already
 *     approved, never another cohort's (BR-23);
 *   · without any evaluation yet;
 *   · the NEWEST version of that trainee's hand-in — BR-19 keeps every version,
 *     and an older one that has been handed in again is never the one to mark;
 *   · the OLDEST by submission time first (D-136), with the id as a stable
 *     tie-break so two hand-ins at the same second never swap places.
 *
 * It only chooses where the trainer lands. It records nothing and computes no
 * mark: the mark and its rules live in EvaluationRecorder and ScoreCalculator
 * (BR-12, BR-13, BR-14).
 *
 * @see BR-19, BR-23 · FR-ASGN-29 · PRD §9.15 · D-136
 */
final class GradingQueue
{
    public function nextAssignmentSubmission(Submission $current): ?Submission
    {
        /** @var Submission|null $next */
        $next = Submission::query()
            ->where('assignment_id', $current->getAttribute('assignment_id'))
            ->whereKeyNot($current->getKey())
            ->whereDoesntHave('evaluations')
            ->newestVersionOnly()
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->first();

        return $next;
    }

    public function nextProjectSubmission(ProjectSubmission $current): ?ProjectSubmission
    {
        /** @var ProjectSubmission|null $next */
        $next = ProjectSubmission::query()
            ->where('final_project_id', $current->getAttribute('final_project_id'))
            ->whereKeyNot($current->getKey())
            ->whereDoesntHave('evaluations')
            ->newestVersionOnly()
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->first();

        return $next;
    }
}
