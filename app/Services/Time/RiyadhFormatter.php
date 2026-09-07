<?php

declare(strict_types=1);

namespace App\Services\Time;

use DateTimeInterface;

/**
 * Renders instants for display: Riyadh timezone, Latin digits, Arabic wording
 * that lives in lang/ar/app.php and never inside this file.
 *
 * Date  -> weekday, day, month, year: app.days.* + j + app.months.* + Y.
 * Time  -> twelve-hour clock plus meridiem word: g:i + app.meridiem.am|pm.
 *
 * PHP's date formatters always emit Latin digits, so no digit transliteration
 * is performed — and none may be added, since Arabic-Indic digits are banned.
 *
 * @see BR-07 · CONSTITUTION art. 11, art. 15 · PRD §9.9.8
 */
final class RiyadhFormatter
{
    /** Indexed by PHP's `w` format (0 = Sunday). */
    private const DAY_KEYS = [
        'sunday',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
    ];

    /** Indexed by PHP's `n` format minus one (0 = January). */
    private const MONTH_KEYS = [
        'january',
        'february',
        'march',
        'april',
        'may',
        'june',
        'july',
        'august',
        'september',
        'october',
        'november',
        'december',
    ];

    /**
     * Weekday, day, month, year: app.days.* + j + app.months.* + Y.
     */
    public function date(DateTimeInterface $t): string
    {
        $riyadh = Clock::toRiyadh($t);

        return $this->dayName($t)
            .' '.$riyadh->format('j')
            .' '.$this->monthName($t)
            .' '.$riyadh->format('Y');
    }

    /**
     * Day, month, year: j + app.months.* + Y - without the day name.
     */
    public function shortDate(DateTimeInterface $t): string
    {
        $riyadh = Clock::toRiyadh($t);

        return $riyadh->format('j').' '.$this->monthName($t).' '.$riyadh->format('Y');
    }

    /**
     * Twelve-hour clock plus meridiem word: g:i + app.meridiem.am|pm.
     * No leading zero on the hour.
     */
    public function time(DateTimeInterface $t): string
    {
        $riyadh = Clock::toRiyadh($t);
        $meridiem = (int) $riyadh->format('G') < 12 ? 'am' : 'pm';

        return $riyadh->format('g:i').' '.(string) __('app.meridiem.'.$meridiem);
    }

    /**
     * Full date and time, joined by the pattern defined in lang/ar/app.php.
     */
    public function dateTime(DateTimeInterface $t): string
    {
        return (string) __('app.date_time', [
            'date' => $this->date($t),
            'time' => $this->time($t),
        ]);
    }

    /**
     * Two twelve-hour times joined by the app.time_range pattern in
     * lang/ar/app.php, each rendered by time() above.
     */
    public function timeRange(DateTimeInterface $from, DateTimeInterface $to): string
    {
        return (string) __('app.time_range', [
            'from' => $this->time($from),
            'to' => $this->time($to),
        ]);
    }

    public function dayName(DateTimeInterface $t): string
    {
        $index = (int) Clock::toRiyadh($t)->format('w');

        return (string) __('app.days.'.self::DAY_KEYS[$index]);
    }

    public function monthName(DateTimeInterface $t): string
    {
        $index = (int) Clock::toRiyadh($t)->format('n') - 1;

        return (string) __('app.months.'.self::MONTH_KEYS[$index]);
    }

    /**
     * Machine readable instant for `datetime` attributes and ICS exports.
     */
    public function iso(DateTimeInterface $t): string
    {
        return Clock::toUtc($t)->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * HH:MM:SS - the countdown used by the attendance card (PRD 9.9.8).
     * Never negative: an elapsed target renders as 00:00:00.
     */
    public function countdown(DateTimeInterface $target, ?DateTimeInterface $from = null): string
    {
        $start = $from === null ? Clock::now() : Clock::toUtc($from);
        $seconds = Clock::toUtc($target)->getTimestamp() - $start->getTimestamp();

        return $this->duration(max(0, $seconds));
    }

    /**
     * Formats a positive number of seconds as HH:MM:SS with Latin digits.
     */
    public function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }

    /**
     * A percentage rendered with Latin digits and at most one decimal place.
     */
    public function percent(float $value): string
    {
        $rounded = round($value, 1);

        return rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.').'%';
    }
}
