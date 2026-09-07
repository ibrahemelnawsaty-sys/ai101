<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Evaluation;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One row of the dashboard's latest-grades card (PRD §9.5.3).
 *
 * @see BR-11, BR-22 · PRD §9.5.3, §9.15
 */
final class LatestGradePresenter extends ViewModel
{
    public static function from(Evaluation $evaluation): self
    {
        return new self([
            'itemTitle' => EvaluatedItemTitle::of($evaluation),
            'score' => Present::decimal($evaluation->getAttribute('score')),
            'maxScore' => Present::decimal($evaluation->getAttribute('max_score')),
            'recordedAt' => Present::toDateTime($evaluation->getAttribute('evaluated_at')),
        ]);
    }
}
