<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\JourneyStep;
use App\Presenters\Support\Present;
use App\Support\Dates;
use App\Support\ViewModel;

/**
 * The progress bar above the journey, and the same figures on the dashboard.
 *
 * Every number comes from JourneyEvaluator::overview(): how many steps are
 * complete is derived from real attendance and real hand-ins, never from a
 * button a participant could press (BR-21, art. 6).
 *
 * @see BR-20, BR-21 · PRD §9.5.3, §9.7
 */
final class JourneyProgressPresenter extends ViewModel
{
    /** An account with no cohort yet: no steps, and no pretence of any. */
    public static function none(): self
    {
        return new self([
            'completedSteps' => 0,
            'totalSteps' => 0,
            'percent' => '0',
            'currentStepTitle' => Dates::ABSENT,
        ]);
    }

    /**
     * @param  array{steps: mixed, statuses: mixed, completed: int, total: int, percent: float, current: JourneyStep|null}  $overview
     */
    public static function from(array $overview): self
    {
        $current = $overview['current'];

        return new self([
            'completedSteps' => $overview['completed'],
            'totalSteps' => $overview['total'],
            'percent' => Present::decimal(Present::clampPercent($overview['percent'])),
            'currentStepTitle' => self::currentTitle($current, $overview['completed'], $overview['total']),
        ]);
    }

    /**
     * There is no current step once every step is done; saying so beats leaving
     * the current-step line hanging with nothing after it (art. 15).
     */
    private static function currentTitle(?JourneyStep $current, int $completed, int $total): string
    {
        if ($current instanceof JourneyStep) {
            return (string) $current->getAttribute('title');
        }

        if ($total > 0 && $completed >= $total) {
            return (string) __('journey.status.completed');
        }

        return Dates::ABSENT;
    }
}
