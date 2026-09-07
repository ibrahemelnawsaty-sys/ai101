<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Support\Dates;
use App\Support\ViewModel;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The calendar view's header strip: which week is on screen, and which week
 * each arrow moves to (PRD §9.8.1).
 *
 * The arrows are `null` at the ends of the programme rather than pointing at a
 * week that does not exist; the template disables the button when they are.
 *
 * `previousWeekIndex` and `nextWeekIndex` carry the value the `?week=` query
 * parameter takes — the week's identifier — so an arrow and the week filter
 * above it speak the same language and a round trip lands where it should.
 *
 * @see PRD §9.8.1 · CONSTITUTION art. 16
 */
final class CalendarPresenter extends ViewModel
{
    /**
     * @param  Collection<int, CalendarDayPresenter>  $days
     */
    public static function from(
        Collection $days,
        ?DateTimeInterface $rangeFrom,
        ?DateTimeInterface $rangeTo,
        ?string $previousWeekIndex,
        ?string $nextWeekIndex,
    ): self {
        return new self([
            'days' => $days,
            'rangeLabel' => Dates::shortRange($rangeFrom, $rangeTo),
            'previousWeekIndex' => $previousWeekIndex,
            'nextWeekIndex' => $nextWeekIndex,
        ]);
    }
}
