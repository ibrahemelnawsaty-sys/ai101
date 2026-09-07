<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\JourneyStepStatus;
use App\Models\JourneyStep;
use App\Presenters\Support\Present;
use App\Services\Journey\JourneyEvaluator;
use App\Support\ViewModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * One of the ten steps of the participant journey (PRD §9.7).
 *
 * The status is JourneyEvaluator's answer, passed in — this class never asks
 * whether a step is done, because there is exactly one place that may answer
 * that and it is not a presenter (BR-21, art. 6). What is decided here is the
 * circle's CSS class, its icon, and which tab the step's action button opens.
 *
 * `statusClass` maps to the timeline styling in resources/css/screens.css:
 * is-done · is-now · is-lock (PRD §9.7.2).
 *
 * @see BR-20, BR-21 · PRD §9.7.1, §9.7.2
 */
final class JourneyStepPresenter extends ViewModel
{
    /**
     * Which tab each unlock rule sends the participant to. A rule with no
     * obvious destination gets none, and the button is simply not rendered.
     */
    private const DESTINATIONS = [
        JourneyEvaluator::RULE_INTRO_ATTENDANCE => 'attendance.index',
        JourneyEvaluator::RULE_WEEK_COMPLETION => 'assignments.index',
        JourneyEvaluator::RULE_PROJECT_SUBMISSION => 'finalProject',
        JourneyEvaluator::RULE_PROJECT_EVALUATION => 'grades',
        JourneyEvaluator::RULE_CLOSING_ATTENDANCE => 'schedule',
        JourneyEvaluator::RULE_CERTIFICATE_ISSUED => 'certificate',
    ];

    /**
     * @param  Collection<int, JourneyDetailPresenter>  $details
     */
    public static function from(JourneyStep $step, JourneyStepStatus $status, Collection $details): self
    {
        $index = (int) $step->getAttribute('index');
        $rule = (string) $step->getAttribute('unlock_rule');
        $routeName = self::DESTINATIONS[$rule] ?? null;
        $hasRoute = $routeName !== null && Route::has($routeName);

        return new self([
            'index' => $index,
            'title' => (string) $step->getAttribute('title'),
            'summary' => Present::text($step->getAttribute('description'))
                ?? (string) __('journey.steps.'.$index.'.rule'),
            'statusLabel' => $status->label(),
            'statusClass' => match ($status) {
                JourneyStepStatus::Completed => 'is-done',
                JourneyStepStatus::Current => 'is-now',
                JourneyStepStatus::Locked => 'is-lock',
            },
            'statusIcon' => match ($status) {
                JourneyStepStatus::Completed => 'check',
                JourneyStepStatus::Current => 'route',
                JourneyStepStatus::Locked => 'lock',
            },
            'isCurrent' => $status === JourneyStepStatus::Current,
            'isLocked' => $status === JourneyStepStatus::Locked,
            'details' => $details,
            'actionRoute' => $hasRoute && $status !== JourneyStepStatus::Locked
                ? route($routeName)
                : null,
            'actionLabel' => (string) __('journey.steps.'.$index.'.action'),
        ]);
    }
}
