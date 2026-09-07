<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Evaluation;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One graded item on the grade sheet (PRD §9.15.3).
 *
 * The trainer's note is published in full and the revision reason with it:
 * BR-13 makes the note mandatory, BR-14 makes the reason mandatory whenever a
 * recorded mark is changed, and a participant who was told their mark moved is
 * entitled to read why (art. 15).
 *
 * @see BR-11, BR-12, BR-13, BR-14, BR-22 · PRD §9.15.3
 */
final class GradeItemPresenter extends ViewModel
{
    public static function graded(Evaluation $evaluation): self
    {
        return new self([
            'id' => (string) $evaluation->getKey(),
            'title' => EvaluatedItemTitle::of($evaluation),
            'isGraded' => true,
            'isLocked' => false,
            'score' => Present::decimal($evaluation->getAttribute('score')),
            'maxScore' => Present::decimal($evaluation->getAttribute('max_score')),
            'graderName' => SubmissionPresenter::graderName($evaluation),
            'feedback' => Present::text($evaluation->getAttribute('feedback')),
            'gradedAt' => Present::toDateTime($evaluation->getAttribute('evaluated_at')),
            'revisionReason' => Present::text($evaluation->getAttribute('revision_reason')),
            'statusLabel' => (string) __('grades.state.graded'),
            'statusVariant' => 'success',
            'statusIcon' => 'check',
        ]);
    }

    /**
     * A published item with no mark yet. It is shown so the sheet lists the
     * whole hundred and not only the part already awarded (PRD §9.15.3).
     */
    public static function pending(string $id, string $title, mixed $maxScore, bool $isLocked): self
    {
        return new self([
            'id' => $id,
            'title' => $title,
            'isGraded' => false,
            'isLocked' => $isLocked,
            'score' => null,
            'maxScore' => Present::decimal($maxScore),
            'graderName' => null,
            'feedback' => null,
            'gradedAt' => null,
            'revisionReason' => null,
            'statusLabel' => (string) __($isLocked ? 'grades.state.locked' : 'grades.state.pending'),
            'statusVariant' => $isLocked ? 'neutral' : 'info',
            'statusIcon' => $isLocked ? 'lock' : 'clock',
        ]);
    }
}
