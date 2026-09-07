<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\EvaluationEntity;
use App\Models\Assignment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Submission;

/**
 * What a mark was awarded for, in words.
 *
 * An evaluation points at a submission, not at the task, so the title lives two
 * relations away. This resolver reads it only from relations the controller has
 * already eager-loaded — `submission.assignment.week` or
 * `projectSubmission.finalProject` — and answers the enum's own label when the
 * chain is not loaded, so a grade row never triggers a query of its own
 * (art. 19, zero N+1 queries).
 *
 * @see BR-11, BR-22 · PRD §9.15
 */
final class EvaluatedItemTitle
{
    public static function of(Evaluation $evaluation): string
    {
        $type = $evaluation->getAttribute('entity_type');

        if ($type === EvaluationEntity::FinalProject) {
            $project = self::finalProject($evaluation);

            return $project === null
                ? EvaluationEntity::FinalProject->label()
                : (string) $project->getAttribute('title');
        }

        $assignment = self::assignment($evaluation);

        return $assignment === null
            ? EvaluationEntity::Assignment->label()
            : (string) $assignment->getAttribute('title');
    }

    /**
     * The week a mark belongs to, used to group the grade sheet. Falls back to
     * the entity label so every mark lands in a named group (art. 17).
     */
    public static function groupOf(Evaluation $evaluation): string
    {
        if ($evaluation->getAttribute('entity_type') === EvaluationEntity::FinalProject) {
            return EvaluationEntity::FinalProject->label();
        }

        $assignment = self::assignment($evaluation);

        if ($assignment !== null && $assignment->relationLoaded('week')) {
            $week = $assignment->getRelation('week');

            if ($week !== null && is_string($week->getAttribute('title'))) {
                return (string) $week->getAttribute('title');
            }
        }

        return EvaluationEntity::Assignment->label();
    }

    public static function assignment(Evaluation $evaluation): ?Assignment
    {
        $submission = self::submission($evaluation);

        if ($submission === null || ! $submission->relationLoaded('assignment')) {
            return null;
        }

        $assignment = $submission->getRelation('assignment');

        return $assignment instanceof Assignment ? $assignment : null;
    }

    public static function submission(Evaluation $evaluation): ?Submission
    {
        if (! $evaluation->relationLoaded('submission')) {
            return null;
        }

        $submission = $evaluation->getRelation('submission');

        return $submission instanceof Submission ? $submission : null;
    }

    public static function finalProject(Evaluation $evaluation): ?FinalProject
    {
        if (! $evaluation->relationLoaded('projectSubmission')) {
            return null;
        }

        $submission = $evaluation->getRelation('projectSubmission');

        if (! $submission instanceof ProjectSubmission || ! $submission->relationLoaded('finalProject')) {
            return null;
        }

        $project = $submission->getRelation('finalProject');

        return $project instanceof FinalProject ? $project : null;
    }
}
