<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\EvaluationEntity;
use App\Models\Cohort;
use App\Models\Evaluation;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The dashboard's total-grade card.
 *
 * The arithmetic is ScoreCalculator's and only ScoreCalculator's: fifty for the
 * assignments plus fifty for the project makes a hundred, and the pass mark is
 * the cohort's (BR-11, art. 6). What this class adds is the honest denominator
 * - recorded so far, out of X points - so a participant with two marks out of
 * a hundred is not told they are failing when nothing else has been graded.
 *
 * @see BR-11, BR-12, BR-22 · PRD §9.5.3, §9.15.3
 */
final class GradeSummaryPresenter extends ViewModel
{
    /**
     * An account with no cohort yet: nothing recorded, which is the card's own
     * empty state rather than a skeleton that never resolves (art. 17).
     */
    public static function none(): self
    {
        return new self([
            'hasAnyEvaluation' => false,
            'finalScore' => '0',
            'grandTotal' => ScoreCalculator::GRAND_TOTAL,
            'recordedMaximum' => '0',
            'passScore' => '0',
            'projectGraded' => false,
        ]);
    }

    /**
     * @param  Collection<int, Evaluation>  $evaluations  this participant's marks in this cohort
     */
    public static function from(
        User $user,
        Cohort $cohort,
        ScoreCalculator $scores,
        Collection $evaluations,
    ): self {
        $breakdown = $scores->breakdown($user, $cohort);

        $recordedMaximum = 0.0;
        $projectGraded = false;

        foreach ($evaluations as $evaluation) {
            $recordedMaximum += (float) $evaluation->getAttribute('max_score');

            if ($evaluation->getAttribute('entity_type') === EvaluationEntity::FinalProject) {
                $projectGraded = true;
            }
        }

        $recordedMaximum = min($recordedMaximum, (float) $breakdown['grand_total']);

        return new self([
            'hasAnyEvaluation' => $evaluations->isNotEmpty(),
            'finalScore' => Present::decimal($breakdown['final']),
            'grandTotal' => $breakdown['grand_total'],
            'recordedMaximum' => Present::decimal($recordedMaximum),
            'passScore' => Present::decimal($breakdown['pass_score']),
            'projectGraded' => $projectGraded,
        ]);
    }
}
