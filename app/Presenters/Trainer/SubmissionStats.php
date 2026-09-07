<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Support\ViewModel;

/**
 * The four counters above the submissions board (PRD §9.11.3).
 *
 * `missing` is the only one that is not a row count: it is how many
 * (participant × published assignment) pairs have produced nothing at all, so a
 * trainer can see the gap rather than infer it from what is present. The
 * controller derives it from two aggregates over the whole cohort, never from
 * the page on screen — a counter that changed when you paged would be lying.
 *
 * @see BR-19, BR-23 · PRD §9.11.3 · CONSTITUTION art. 5
 */
final class SubmissionStats extends ViewModel
{
    public static function of(int $submitted, int $late, int $missing, int $awaitingGrading): self
    {
        return new self([
            'submitted' => $submitted,
            'late' => $late,
            'missing' => max(0, $missing),
            'awaitingGrading' => $awaitingGrading,
        ]);
    }
}
