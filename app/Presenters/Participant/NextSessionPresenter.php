<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Http\Controllers\Participant\LiveController;
use App\Models\Session;
use App\Services\Attendance\AttendanceWindow;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * The dashboard's next-session card.
 *
 * `joinWindowOpen` mirrors the server decision and nothing more: the meeting
 * URL is not in this object, is not in the page, and is handed over only by the
 * guarded join endpoint, which re-checks the same window on arrival (BR-24).
 * The window itself is PRD §9.10's quarter of an hour, taken from the single
 * constant that endpoint uses.
 *
 * A cohort with nothing scheduled yields `isMissing`, which is an empty state
 * and not an error (art. 17).
 *
 * @see BR-07, BR-24 · PRD §9.5.3, §9.10 · CONSTITUTION art. 5, art. 17
 */
final class NextSessionPresenter extends ViewModel
{
    public static function missing(): self
    {
        return new self([
            'isMissing' => true,
            'id' => null,
            'title' => null,
            'startsAt' => null,
            'endsAt' => null,
            'trainerName' => null,
            'joinWindowOpen' => false,
            'joinOpensBeforeMinutes' => LiveController::JOIN_OPENS_BEFORE_START_MINUTES,
        ]);
    }

    public static function from(Session $session, AttendanceWindow $window, CarbonImmutable $now): self
    {
        $startsAt = $window->startsAt($session);
        $endsAt = $window->endsAt($session);
        $opensAt = $startsAt->subMinutes(LiveController::JOIN_OPENS_BEFORE_START_MINUTES);

        return new self([
            'isMissing' => false,
            'id' => (string) $session->getKey(),
            'title' => (string) $session->getAttribute('title'),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'trainerName' => SessionPresenter::trainerName($session),
            'joinWindowOpen' => ! $window->isCancelled($session)
                && $now->greaterThanOrEqualTo($opensAt)
                && $now->lessThanOrEqualTo($endsAt),
            'joinOpensBeforeMinutes' => LiveController::JOIN_OPENS_BEFORE_START_MINUTES,
        ]);
    }
}
