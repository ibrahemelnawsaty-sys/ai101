<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
use DateTimeInterface;

/**
 * Static reading-room for dates, used from Blade.
 *
 * Views need a terse call — `Dates::longDate($session->startsAt)` — and they
 * routinely hold a value that is legitimately absent: a participant who has
 * never signed in has no last_login_at, an unissued certificate has no
 * issued_at. Every method here therefore accepts null and answers with the
 * placeholder rather than making the caller guard, because a view that has to
 * guard is a view that will eventually forget to.
 *
 * All formatting work belongs to RiyadhFormatter; this class only resolves it
 * and handles absence. Storage stays UTC and display stays Asia/Riyadh —
 * neither decision is taken here (CONSTITUTION art. 11).
 *
 * @see PRD §13 · CONSTITUTION art. 6, art. 11, art. 15
 */
final class Dates
{
    /**
     * Shown wherever a date genuinely does not exist yet. An em dash, not an
     * empty cell: a blank reads as a rendering bug, this reads as "no value".
     */
    public const ABSENT = '—';

    /** Weekday, day, month, year: app.days.* + j + app.months.* + Y. */
    public static function longDate(?DateTimeInterface $at): string
    {
        return $at === null ? self::ABSENT : self::formatter()->date($at);
    }

    /** Day, month, year: j + app.months.* + Y, no weekday. */
    public static function shortDate(?DateTimeInterface $at): string
    {
        return $at === null ? self::ABSENT : self::formatter()->shortDate($at);
    }

    /** Twelve-hour clock plus meridiem word: g:i + app.meridiem.am|pm. */
    public static function time(?DateTimeInterface $at): string
    {
        return $at === null ? self::ABSENT : self::formatter()->time($at);
    }

    /**
     * Alias of time(). The Blade layer calls the twelve-hour clock `time12`
     * everywhere, which reads better beside `timeRange12` and makes the
     * twelve-hour rule of art. 15 visible at the call site.
     */
    public static function time12(?DateTimeInterface $at): string
    {
        return self::time($at);
    }

    /** Long date and twelve-hour time joined by app.date_time. */
    public static function dateTime(?DateTimeInterface $at): string
    {
        return $at === null ? self::ABSENT : self::formatter()->dateTime($at);
    }

    /** Two twelve-hour times joined by app.time_range. */
    public static function timeRange(?DateTimeInterface $from, ?DateTimeInterface $to): string
    {
        if ($from === null || $to === null) {
            return self::ABSENT;
        }

        return self::formatter()->timeRange($from, $to);
    }

    /** Alias of timeRange(), named for the twelve-hour clock it prints. */
    public static function timeRange12(?DateTimeInterface $from, ?DateTimeInterface $to): string
    {
        return self::timeRange($from, $to);
    }

    /**
     * A one-week range joined by app.time_range: the start day alone, then the
     * end as day + app.months.* + Y, collapsing the repeated month and year.
     * Falls back to two full short dates when the range crosses a month, since
     * a cross-month range has to name both months.
     */
    public static function shortRange(?DateTimeInterface $from, ?DateTimeInterface $to): string
    {
        if ($from === null || $to === null) {
            return self::ABSENT;
        }

        $start = Clock::toRiyadh($from);
        $end = Clock::toRiyadh($to);

        if ($start->format('Y-m') !== $end->format('Y-m')) {
            return __('app.time_range', [
                'from' => self::formatter()->shortDate($from),
                'to' => self::formatter()->shortDate($to),
            ]);
        }

        return __('app.time_range', [
            'from' => (string) $start->day,
            'to' => self::formatter()->shortDate($to),
        ]);
    }

    /**
     * A coarse "time ago" phrase from app.relative.* (now, minutes, hours,
     * days, weeks). Anything older than a month falls back to the absolute
     * date, because a month count makes a reader do arithmetic to answer a
     * question the date itself answers.
     */
    public static function relative(?DateTimeInterface $at): string
    {
        if ($at === null) {
            return self::ABSENT;
        }

        $seconds = Clock::now()->getTimestamp() - Clock::toUtc($at)->getTimestamp();

        // A future instant is not "ago"; show it plainly.
        if ($seconds < 0) {
            return self::formatter()->dateTime($at);
        }

        if ($seconds < 60) {
            return __('app.relative.now');
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return trans_choice('app.relative.minutes', $minutes, ['count' => $minutes]);
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return trans_choice('app.relative.hours', $hours, ['count' => $hours]);
        }

        $days = intdiv($hours, 24);

        if ($days < 7) {
            return trans_choice('app.relative.days', $days, ['count' => $days]);
        }

        if ($days < 30) {
            $weeks = intdiv($days, 7);

            return trans_choice('app.relative.weeks', $weeks, ['count' => $weeks]);
        }

        return self::formatter()->date($at);
    }

    /**
     * Machine readable UTC, for `<time datetime>` and ICS exports. Returns an
     * empty string when absent so the attribute can simply be omitted.
     */
    public static function isoUtc(?DateTimeInterface $at): string
    {
        return $at === null ? '' : self::formatter()->iso($at);
    }

    private static function formatter(): RiyadhFormatter
    {
        return app(RiyadhFormatter::class);
    }
}
