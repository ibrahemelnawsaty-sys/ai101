<?php

declare(strict_types=1);

namespace App\Presenters\Shared;

use App\Models\Cohort;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * BR-11 — the assignment ceilings of a cohort should add up to 50.
 *
 * When they do not, the trainer is warned on their own board and is never
 * blocked (PROJECT-CONTRACT §7). The controllers used to hand the view a bare
 * boolean while the template read `$totalsWarning->current` and `->expected`,
 * so the warning fatalled exactly when it was needed. Both numbers are computed
 * by ScoreCalculator, which owns BR-11, and merely carried here.
 *
 * `null` means the totals balance and no warning is drawn.
 *
 * @see BR-11 · PRD §9.15.1 · PROJECT-CONTRACT §7 · CONSTITUTION art. 6
 */
final class TotalsWarning extends ViewModel
{
    public static function forCohort(ScoreCalculator $scores, ?Cohort $cohort): ?self
    {
        if ($cohort === null || $scores->assignmentsTotalIsBalanced($cohort)) {
            return null;
        }

        return new self([
            'current' => rtrim(rtrim(number_format($scores->assignmentsMaxTotal($cohort), 2, '.', ''), '0'), '.'),
            'expected' => ScoreCalculator::ASSIGNMENTS_TOTAL,
        ]);
    }
}
