<?php

declare(strict_types=1);

/**
 * Shared support for the seeders: the Arabic seed CONTENT and the cohort calendar.
 *
 * Why the content lives in JSON and not in these classes:
 * every string a user sees that is not UI chrome is DATA, editable from the
 * admin panel (BR-31, BR-36) — and no Arabic text may appear inside a .php file
 * (Constitution art. 13 #3). Holding it in database/seeders/data/*.json honours
 * both rules at once.
 *
 * The calendar is anchored to Clock, never to a literal date, so `migrate:fresh
 * --seed` produces a cohort in the same relative position on any day it is run:
 *
 *   day  -3   intro session (the Thursday before the cohort starts)
 *   day   0   cohort starts — always a Sunday, 4 weeks before the current week
 *   day 0..27 four weeks, sessions on Sun/Tue/Thu 19:00–21:30 Riyadh
 *   day  35   closing ceremony — always 1 to 7 days in the FUTURE
 *
 * That fixed geometry is what guarantees every dashboard has both settled
 * history (attendance, grades) and something still ahead (the closing session).
 *
 * @see PRD §7 · BR-07, BR-31, BR-36 · Constitution art. 11, art. 29
 */

namespace Database\Seeders;

use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class SeedContent
{
    /** Day offset, from the cohort start, of the introductory session. */
    public const INTRO_DAY = -3;

    /** Day offset, from the cohort start, of the closing ceremony. */
    public const CLOSING_DAY = 35;

    /**
     * Day offsets inside each week that carry a session: Saturday, Monday,
     * Wednesday — the pattern the centre publishes in its programme guide.
     *
     * The offsets are unchanged; the week now STARTS on Saturday (see
     * cohortStart), which is what turns 0/2/4 from Sun/Tue/Thu into Sat/Mon/Wed.
     */
    public const SESSION_WEEKDAY_OFFSETS = [0, 2, 4];

    /*
     | The daily window from the guide: five to seven in the evening, Riyadh.
     | It ran 19:00–21:30 here while the seeded FAQ told visitors 19:00–21:30 as
     | well — both wrong together, which is why neither looked wrong.
     */
    public const SESSION_START_TIME = '17:00:00';

    public const SESSION_END_TIME = '19:00:00';

    public const INTRO_END_TIME = '19:00:00';

    public const CLOSING_END_TIME = '19:00:00';

    public const WEEK_COUNT = 4;

    /** @var array<string, mixed>|null */
    private static ?array $content = null;

    /**
     * The whole seed content, decoded once per process.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$content !== null) {
            return self::$content;
        }

        $path = __DIR__.'/data/ai101-content.json';
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Unable to read the seed content file at {$path}.");
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("The seed content file at {$path} is not valid JSON.");
        }

        return self::$content = $decoded;
    }

    /**
     * One top-level section of the seed content.
     *
     * @return array<array-key, mixed>
     */
    public static function section(string $key): array
    {
        $all = self::all();

        if (! isset($all[$key]) || ! is_array($all[$key])) {
            throw new \RuntimeException("The seed content has no section named '{$key}'.");
        }

        return $all[$key];
    }

    /**
     * Day 0 of the cohort: Riyadh midnight on the Sunday four weeks before the
     * current week. Derived from Clock, never from a literal date (BR-07).
     */
    public static function cohortStart(): CarbonImmutable
    {
        return Clock::riyadh()
            ->startOfDay()
            // Saturday, because the programme's own week does: the guide runs
            // Saturday / Monday / Wednesday, and anchoring to Sunday shifted
            // every seeded session a day off the published schedule.
            ->startOfWeek(CarbonInterface::SATURDAY)
            ->subWeeks(4);
    }

    /**
     * Riyadh midnight on a given day offset from the cohort start.
     */
    public static function day(int $offset): CarbonImmutable
    {
        return self::cohortStart()->addDays($offset);
    }

    /**
     * The calendar date, as stored in `sessions.date`, for a day offset.
     */
    public static function dateOn(int $offset): string
    {
        return self::day($offset)->format('Y-m-d');
    }

    /**
     * Compose a Riyadh wall-clock day and time into the UTC instant that is
     * actually stored. Sessions keep date and time separately (PRD §7.3), so
     * every derived instant has to be built this way.
     */
    public static function instant(int $dayOffset, string $time): CarbonImmutable
    {
        return self::atTime(self::day($dayOffset), $time);
    }

    /**
     * Compose an already-resolved Riyadh day with a wall-clock time, in UTC.
     */
    public static function atTime(CarbonImmutable $riyadhDay, string $time): CarbonImmutable
    {
        $parts = array_map('intval', explode(':', $time));

        return $riyadhDay
            ->setTime($parts[0], $parts[1] ?? 0, $parts[2] ?? 0)
            ->setTimezone('UTC');
    }

    /**
     * A stored `sessions.date` value as a Riyadh midnight, whether the model
     * casts the column to a date or hands back the raw string.
     */
    public static function riyadhDay(mixed $storedDate): CarbonImmutable
    {
        $date = $storedDate instanceof \DateTimeInterface
            ? $storedDate->format('Y-m-d')
            : (string) $storedDate;

        $day = CarbonImmutable::createFromFormat('Y-m-d', substr($date, 0, 10), 'Asia/Riyadh');

        // Carbon 3 returns null — never false — when the string does not match the
        // format, so a `=== false` test never fires and startOfDay() below would be
        // reached on null.
        if (! $day instanceof CarbonImmutable) {
            throw new \RuntimeException("Unable to read the session date '{$date}'.");
        }

        return $day->startOfDay();
    }

    /**
     * A stored `sessions.start_time` / `end_time` value as `H:i:s`.
     */
    public static function wallClockTime(mixed $storedTime): string
    {
        if ($storedTime instanceof \DateTimeInterface) {
            return $storedTime->format('H:i:s');
        }

        $time = (string) $storedTime;

        return strlen($time) > 8 ? substr($time, 11, 8) : $time;
    }

    /**
     * The UTC instant a stored session date and wall-clock time denote.
     */
    public static function sessionInstant(mixed $storedDate, mixed $storedTime): CarbonImmutable
    {
        return self::atTime(self::riyadhDay($storedDate), self::wallClockTime($storedTime));
    }

    /**
     * Day offsets of the twelve training sessions, in order.
     *
     * @return list<int>
     */
    public static function trainingSessionDays(): array
    {
        $days = [];

        for ($week = 1; $week <= self::WEEK_COUNT; $week++) {
            foreach (self::SESSION_WEEKDAY_OFFSETS as $offset) {
                $days[] = (($week - 1) * 7) + $offset;
            }
        }

        return $days;
    }

    /**
     * The 1-based week number a training session ordinal belongs to.
     */
    public static function weekOfTrainingSession(int $ordinal): int
    {
        return intdiv($ordinal, count(self::SESSION_WEEKDAY_OFFSETS)) + 1;
    }
}
