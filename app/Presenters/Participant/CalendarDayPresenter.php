<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One column of the visual calendar (PRD §9.8.1).
 *
 * Today is decided from the server clock in Riyadh time, so a participant
 * travelling with a laptop still sees the programme's today (BR-07, art. 11).
 *
 * @see BR-07 · PRD §9.8.1
 */
final class CalendarDayPresenter extends ViewModel
{
    /**
     * @param  Collection<int, SessionPresenter>  $sessions
     */
    public static function from(CarbonImmutable $date, CarbonImmutable $now, Collection $sessions): self
    {
        return new self([
            'date' => $date,
            'isToday' => $date->toDateString() === Clock::toRiyadh($now)->toDateString(),
            'weekdayLabel' => app(RiyadhFormatter::class)->dayName($date),
            'sessions' => $sessions,
        ]);
    }
}
