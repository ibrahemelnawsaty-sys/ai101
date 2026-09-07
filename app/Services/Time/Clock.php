<?php

declare(strict_types=1);

namespace App\Services\Time;

use App\Exceptions\TimeException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The single source of time for the entire platform.
 *
 * Storage is always UTC, display is always Asia/Riyadh, and server time is the
 * sole reference for every calculation. No other file in the codebase may call
 * now(), Carbon::now(), new DateTime, time() or date() — gate G4 enforces it.
 *
 * @see BR-07 · PRD §9.9.1 · CONSTITUTION art. 11 · CONTRACT §5
 */
final class Clock
{
    public const STORAGE_TIMEZONE = 'UTC';

    public const DISPLAY_TIMEZONE = 'Asia/Riyadh';

    /**
     * Frozen instant used by tests. Deliberately kept local to this class so
     * that faking time never leaks into Carbon's global test-now state.
     */
    private static ?CarbonImmutable $frozenAt = null;

    /**
     * Current server instant, always in UTC.
     */
    public static function now(): CarbonImmutable
    {
        if (self::$frozenAt instanceof CarbonImmutable) {
            return self::$frozenAt;
        }

        return CarbonImmutable::now(self::storageTimezone());
    }

    /**
     * Current server instant expressed in Asia/Riyadh, for display only.
     */
    public static function riyadh(): CarbonImmutable
    {
        return self::now()->setTimezone(self::displayTimezone());
    }

    /**
     * Convert any instant to Asia/Riyadh for display.
     */
    public static function toRiyadh(DateTimeInterface $t): CarbonImmutable
    {
        return CarbonImmutable::instance($t)->setTimezone(self::displayTimezone());
    }

    /**
     * Convert any instant to UTC for storage or comparison.
     */
    public static function toUtc(DateTimeInterface $t): CarbonImmutable
    {
        return CarbonImmutable::instance($t)->setTimezone(self::storageTimezone());
    }

    /**
     * Freeze time for tests. Passing null restores the real clock.
     */
    public static function fake(?DateTimeInterface $at): void
    {
        self::$frozenAt = $at === null
            ? null
            : CarbonImmutable::instance($at)->setTimezone(self::storageTimezone());
    }

    /**
     * Restore the real clock.
     */
    public static function reset(): void
    {
        self::$frozenAt = null;
    }

    public static function isFaked(): bool
    {
        return self::$frozenAt instanceof CarbonImmutable;
    }

    /**
     * Build a UTC instant from a calendar date and a wall-clock time that are
     * both expressed in Asia/Riyadh. This is how sessions are stored: a `date`
     * column plus `start_time` / `end_time` columns in Riyadh wall time.
     *
     * @param  DateTimeInterface|string|null  $date
     * @param  DateTimeInterface|string|null  $time
     *
     * @throws TimeException when either component cannot be read — refusing is
     *                       safer than guessing (CONSTITUTION art. 7).
     */
    public static function composeRiyadh(mixed $date, mixed $time): CarbonImmutable
    {
        $composed = CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            self::normalizeDate($date).' '.self::normalizeTime($time),
            self::displayTimezone(),
        );

        if (! $composed instanceof CarbonImmutable) {
            throw TimeException::unreadable();
        }

        return $composed->setTimezone(self::storageTimezone());
    }

    /**
     * Parse a wall-clock string written in Riyadh time into a UTC instant.
     *
     * @throws TimeException
     */
    public static function fromRiyadh(string $value): CarbonImmutable
    {
        $trimmed = trim($value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2})?)/', $trimmed, $matches) !== 1) {
            throw TimeException::unreadable();
        }

        return self::composeRiyadh($matches[1], $matches[2]);
    }

    public static function storageTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::STORAGE_TIMEZONE);
    }

    public static function displayTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::DISPLAY_TIMEZONE);
    }

    /**
     * @throws TimeException
     */
    private static function normalizeDate(mixed $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($date), $matches) === 1) {
            return $matches[1].'-'.$matches[2].'-'.$matches[3];
        }

        throw TimeException::unreadable();
    }

    /**
     * @throws TimeException
     */
    private static function normalizeTime(mixed $time): string
    {
        if ($time instanceof DateTimeInterface) {
            return $time->format('H:i:s');
        }

        if (is_string($time) && preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', trim($time), $matches) === 1) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            $second = isset($matches[3]) ? (int) $matches[3] : 0;

            if ($hour > 23 || $minute > 59 || $second > 59) {
                throw TimeException::unreadable();
            }

            return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        }

        throw TimeException::unreadable();
    }
}
