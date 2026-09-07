<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Presenters\Support\Present;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * The grade sheet's header: the total out of a hundred and how it splits.
 *
 * Every figure is ScoreCalculator::breakdown()'s. The only thing added here is
 * `remainingPoints` — how many of the hundred are still unawarded — and it is
 * arithmetic on that same answer, not a second opinion about it (BR-11, art. 6).
 *
 * @see BR-11, BR-12, BR-22 · PRD §9.15.3
 */
final class GradeTotalPresenter extends ViewModel
{
    /** An account with no cohort yet: nothing awarded, and nothing pretended. */
    public static function none(): self
    {
        return new self([
            'finalScore' => '0',
            'grandTotal' => ScoreCalculator::GRAND_TOTAL,
            'assignmentsScore' => '0',
            'assignmentsTotal' => ScoreCalculator::ASSIGNMENTS_TOTAL,
            'projectScore' => '0',
            'projectTotal' => ScoreCalculator::PROJECT_TOTAL,
            'projectGraded' => false,
            'passScore' => '0',
            'passes' => false,
            'remainingPoints' => (string) ScoreCalculator::GRAND_TOTAL,
        ]);
    }

    /**
     * @param  array{assignments: float, assignments_total: int, project: float, project_total: int, final: float, grand_total: int, pass_score: float, passes: bool}  $breakdown
     */
    public static function from(array $breakdown, bool $projectGraded): self
    {
        $remaining = max(0.0, (float) $breakdown['grand_total'] - $breakdown['final']);

        return new self([
            'finalScore' => Present::decimal($breakdown['final']),
            'grandTotal' => $breakdown['grand_total'],
            'assignmentsScore' => Present::decimal($breakdown['assignments']),
            'assignmentsTotal' => $breakdown['assignments_total'],
            'projectScore' => Present::decimal($breakdown['project']),
            'projectTotal' => $breakdown['project_total'],
            'projectGraded' => $projectGraded,
            'passScore' => Present::decimal($breakdown['pass_score']),
            'passes' => $breakdown['passes'],
            'remainingPoints' => Present::decimal($remaining),
        ]);
    }
}
