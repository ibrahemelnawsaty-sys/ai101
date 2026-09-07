<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Support\ViewModel;

/**
 * Submissions still waiting for a grade, grouped by cohort.
 *
 * The console shows a queue to work through, not a list of every ungraded row:
 * a cohort with forty ungraded submissions is one line saying forty, which is
 * the shape the administrator can act on (art. 19).
 *
 * @see PRD §9.18 · BR-13, BR-27
 */
final class UngradedGroup extends ViewModel
{
    public static function of(string $cohortName, int $count): self
    {
        return new self([
            'title' => $cohortName,
            'cohortName' => $cohortName,
            'count' => $count,
            'items' => [],
        ]);
    }
}
