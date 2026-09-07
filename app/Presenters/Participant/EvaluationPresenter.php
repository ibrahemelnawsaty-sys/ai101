<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Evaluation;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The final project's recorded mark and the trainer's note (PRD §9.14).
 *
 * The note is published whole. BR-13 makes it mandatory with every mark and
 * PRD §9.15.3 makes showing all of it mandatory too - no clamp, and no
 * read-more link.
 *
 * @see BR-13, BR-14, BR-22 · PRD §9.14, §9.15.3
 */
final class EvaluationPresenter extends ViewModel
{
    public static function from(Evaluation $evaluation): self
    {
        return new self([
            'score' => Present::decimal($evaluation->getAttribute('score')),
            'feedback' => Present::text($evaluation->getAttribute('feedback')),
            'graderName' => SubmissionPresenter::graderName($evaluation),
            'recordedAt' => Present::toDateTime($evaluation->getAttribute('evaluated_at')),
        ]);
    }
}
