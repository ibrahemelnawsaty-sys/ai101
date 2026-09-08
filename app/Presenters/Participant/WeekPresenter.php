<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Week;
use App\Presenters\Support\Present;
use App\Services\Time\Clock;
use App\Support\Dates;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One programme week, as the schedule accordion and the assignments accordion
 * both read it. The two screens fill different halves — sessions on one,
 * assignments on the other — so the half a screen does not use is published
 * empty rather than absent, and neither template needs a guard.
 *
 * `isCurrent` is decided against the server clock, never the browser's (BR-07).
 *
 * @see BR-07, BR-22 · PRD §9.8, §9.11.1
 */
final class WeekPresenter extends ViewModel
{
    /**
     * @param  Collection<int, SessionPresenter>  $sessions
     * @param  Collection<int, AssignmentPresenter>  $assignments
     */
    public static function from(
        Week $week,
        CarbonImmutable $now,
        Collection $sessions,
        Collection $assignments,
        int $attendedCount = 0,
        float $minimumRate = 0.0,
    ): self {
        $startsOn = Present::toDateTime($week->getAttribute('start_date'));
        $endsOn = Present::toDateTime($week->getAttribute('end_date'));

        $sessionCount = $sessions->count();
        $rate = $sessionCount === 0 ? 0.0 : ($attendedCount / $sessionCount) * 100;
        $variant = $sessionCount === 0 ? 'neutral' : Present::rateVariant($rate, $minimumRate);

        return new self([
            'title' => (string) $week->getAttribute('title'),
            'paddedIndex' => str_pad((string) (int) $week->getAttribute('index'), 2, '0', STR_PAD_LEFT),
            'startsOn' => $startsOn,
            'endsOn' => $endsOn,
            'isCurrent' => self::covers($now, $startsOn, $endsOn),
            'sessionCount' => $sessionCount,
            'sessions' => $sessions,
            'assignments' => $assignments,
            'attendedCount' => $attendedCount,
            'attendanceVariant' => $variant,
            'attendanceIcon' => $variant === 'neutral' ? 'cal' : Present::rateIcon($variant),
        ]);
    }

    /**
     * The group that holds what belongs to the cohort but to no week.
     *
     * PRD §7.3 says `week_id` is left empty for a session outside the weeks, and
     * the schema allows the same for an assignment. Before this existed, such a
     * row was grouped under a week key that matched no week and so rendered
     * nowhere at all — present in the data, invisible on the screen.
     *
     * It has no dates, so it is never "the current week" and its index reads as
     * absent. The title arrives already translated: no Arabic lives in PHP
     * (Article 15).
     *
     * @param  Collection<int, SessionPresenter>  $sessions
     * @param  Collection<int, AssignmentPresenter>  $assignments
     */
    public static function unscheduled(
        string $title,
        Collection $sessions,
        Collection $assignments,
        int $attendedCount = 0,
        float $minimumRate = 0.0,
    ): self {
        $sessionCount = $sessions->count();
        $rate = $sessionCount === 0 ? 0.0 : ($attendedCount / $sessionCount) * 100;
        $variant = $sessionCount === 0 ? 'neutral' : Present::rateVariant($rate, $minimumRate);

        return new self([
            'title' => $title,
            'paddedIndex' => Dates::ABSENT,
            'startsOn' => null,
            'endsOn' => null,
            'isCurrent' => false,
            'sessionCount' => $sessionCount,
            'sessions' => $sessions,
            'assignments' => $assignments,
            'attendedCount' => $attendedCount,
            'attendanceVariant' => $variant,
            'attendanceIcon' => $variant === 'neutral' ? 'cal' : Present::rateIcon($variant),
        ]);
    }

    private static function covers(CarbonImmutable $now, mixed $startsOn, mixed $endsOn): bool
    {
        if (! $startsOn instanceof \DateTimeInterface || ! $endsOn instanceof \DateTimeInterface) {
            return false;
        }

        $today = Clock::toRiyadh($now)->toDateString();

        return Clock::toRiyadh($startsOn)->toDateString() <= $today
            && Clock::toRiyadh($endsOn)->toDateString() >= $today;
    }
}
