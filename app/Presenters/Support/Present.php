<?php

declare(strict_types=1);

namespace App\Presenters\Support;

use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
use Carbon\CarbonImmutable;

/**
 * The one place a presentation decision is taken.
 *
 * Every variant, icon and remaining-time sentence a participant screen shows is
 * decided here, on the server, from a number a domain service already produced.
 * Nothing in this class computes a business rule: it colours an answer, it
 * never gives one (CONSTITUTION art. 5, art. 6).
 *
 * The thresholds are quoted, not invented:
 *   attendance ring — PRD §9.9.6: green above 85, burnt orange 70..85, red below 70
 *   assignment due  — PRD §9.11.1: normal above 48h, warning under 48h, danger under 6h
 *   new resource    — PRD §9.5.3: added within the last three days
 *
 * @see PRD §9.5.3, §9.9.6, §9.11.1 · BR-26 · CONSTITUTION art. 5, art. 6, art. 14
 */
final class Present
{
    /** PRD §9.9.6 — at or above this the attendance ring is green. */
    public const RATE_GOOD = 85.0;

    /** PRD §9.9.6 — at or above this it is burnt orange; below it, red. */
    public const RATE_FAIR = 70.0;

    /**
     * How close to the cohort minimum still raises the "watch your attendance"
     * notice. PRD §9.9.6 asks for the notice but names no distance, so ten
     * points is an assumption recorded in DECISIONS.md and declared in the
     * batch report. It only widens a warning; it never relaxes BR-26.
     */
    public const NEAR_MINIMUM_MARGIN = 10.0;

    /** PRD §9.11.1 */
    public const DUE_SOON_SECONDS = 172800;

    /** PRD §9.11.1 */
    public const DUE_CRITICAL_SECONDS = 21600;

    /** PRD §9.5.3 - added within the last three days. */
    public const NEW_RESOURCE_SECONDS = 259200;

    private const KIB = 1024;

    /**
     * Variant for a progress bar or a pill: success | warning | error.
     *
     * A rate below the cohort's own minimum is never reported as success, even
     * when it clears the generic 85% band, because the cohort minimum is the
     * threshold BR-26 actually judges the certificate on.
     */
    public static function rateVariant(float $rate, float $minimum): string
    {
        if ($rate < self::RATE_FAIR) {
            return 'error';
        }

        if ($rate < $minimum) {
            return 'warning';
        }

        return $rate >= self::RATE_GOOD ? 'success' : 'warning';
    }

    /**
     * Variant for the circular indicator, whose CSS is keyed ok | warn | bad
     * (resources/css/screens.css — .arate--warn, .arate--bad).
     */
    public static function rateRing(float $rate, float $minimum): string
    {
        return match (self::rateVariant($rate, $minimum)) {
            'success' => 'ok',
            'warning' => 'warn',
            default => 'bad',
        };
    }

    public static function rateIcon(string $variant): string
    {
        return $variant === 'success' ? 'check' : 'warn';
    }

    public static function isNearMinimum(float $rate, float $minimum): bool
    {
        return $rate < $minimum + self::NEAR_MINIMUM_MARGIN;
    }

    /**
     * Seconds left until an instant, or null when there is no instant at all.
     * Never negative: a passed deadline reads zero and hasPassed() says the rest.
     */
    public static function secondsUntil(?\DateTimeInterface $target, CarbonImmutable $at): ?int
    {
        if ($target === null) {
            return null;
        }

        return max(0, Clock::toUtc($target)->getTimestamp() - $at->getTimestamp());
    }

    public static function hasPassed(?\DateTimeInterface $target, CarbonImmutable $at): bool
    {
        return $target !== null && $at->getTimestamp() > Clock::toUtc($target)->getTimestamp();
    }

    /**
     * neutral | warning | error — PRD §9.11.1. A deadline that has already
     * passed is `error`; no deadline at all is `neutral`.
     */
    public static function urgencyVariant(?\DateTimeInterface $dueAt, CarbonImmutable $at): string
    {
        if ($dueAt === null) {
            return 'neutral';
        }

        if (self::hasPassed($dueAt, $at)) {
            return 'error';
        }

        $seconds = self::secondsUntil($dueAt, $at) ?? 0;

        if ($seconds < self::DUE_CRITICAL_SECONDS) {
            return 'error';
        }

        return $seconds < self::DUE_SOON_SECONDS ? 'warning' : 'neutral';
    }

    public static function urgencyIcon(string $variant): string
    {
        return $variant === 'error' ? 'warn' : 'clock';
    }

    /**
     * "3 hours left" / "deadline passed" / "no deadline", in the reader's language.
     */
    public static function remainingLabel(?\DateTimeInterface $dueAt, CarbonImmutable $at): string
    {
        if ($dueAt === null) {
            return (string) __('app.remaining.none');
        }

        if (self::hasPassed($dueAt, $at)) {
            return (string) __('app.remaining.passed');
        }

        $seconds = self::secondsUntil($dueAt, $at) ?? 0;

        if ($seconds < 60) {
            return (string) __('app.remaining.now');
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return (string) trans_choice('app.remaining.minutes', $minutes, ['count' => $minutes]);
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return (string) trans_choice('app.remaining.hours', $hours, ['count' => $hours]);
        }

        $days = intdiv($hours, 24);

        return (string) trans_choice('app.remaining.days', $days, ['count' => $days]);
    }

    /**
     * "3 days" / "5 hours" / "less than a minute" — a span in words, with no
     * verb. For copy that supplies its own ("…:countdown left…"), where
     * remainingLabel()'s wording would say "left" twice, and HH:MM:SS would put
     * "203:59:00" in a letter (D-68). Zero or less reads as the smallest unit.
     */
    public static function durationLabel(?\DateTimeInterface $until, CarbonImmutable $at): string
    {
        $seconds = self::secondsUntil($until, $at) ?? 0;

        if ($seconds < 60) {
            return (string) __('app.duration.moment');
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return (string) trans_choice('app.duration.minutes', $minutes, ['count' => $minutes]);
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return (string) trans_choice('app.duration.hours', $hours, ['count' => $hours]);
        }

        $days = intdiv($hours, 24);

        return (string) trans_choice('app.duration.days', $days, ['count' => $days]);
    }

    /** The HH:MM:SS countdown wording of PRD §9.9.8. */
    public static function countdown(?\DateTimeInterface $target, CarbonImmutable $at): string
    {
        $formatter = app(RiyadhFormatter::class);

        return $target === null ? $formatter->duration(0) : $formatter->countdown($target, $at);
    }

    /**
     * "2.4 MB", in the reader's language. Null when the size is unknown, so a
     * view can leave the line out rather than print a zero that is not a fact.
     */
    public static function fileSize(mixed $bytes): ?string
    {
        if (! is_numeric($bytes)) {
            return null;
        }

        $value = (float) $bytes;

        if ($value <= 0.0) {
            return null;
        }

        if ($value < self::KIB) {
            return (string) __('app.file_size.bytes', ['size' => self::decimal($value)]);
        }

        $kb = $value / self::KIB;

        if ($kb < self::KIB) {
            return (string) __('app.file_size.kb', ['size' => self::decimal($kb)]);
        }

        $mb = $kb / self::KIB;

        if ($mb < self::KIB) {
            return (string) __('app.file_size.mb', ['size' => self::decimal($mb)]);
        }

        return (string) __('app.file_size.gb', ['size' => self::decimal($mb / self::KIB)]);
    }

    /**
     * Latin digits, at most one decimal place, no trailing zero decimal (art. 15).
     */
    public static function decimal(mixed $value): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        $formatted = number_format(round($number, 1), 1, '.', '');

        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /**
     * A percentage for a progress bar or a ring, clamped to 0..100.
     */
    public static function clampPercent(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return round(max(0.0, min(100.0, $number)), 1);
    }

    public static function toDateTime(mixed $value): ?\DateTimeInterface
    {
        return $value instanceof \DateTimeInterface ? $value : null;
    }

    public static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A column that should hold a list of short strings, read defensively:
     * a JSON array, a JSON array of objects with a `title`, or a plain text
     * block one item per line. Seeded data uses all three shapes.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $lines = preg_split('/\r\n|\r|\n/', $value);

            if ($lines === false) {
                return [];
            }

            return array_values(array_filter(
                array_map(static fn (string $line): string => trim($line), $lines),
                static fn (string $line): bool => $line !== '',
            ));
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);

                continue;
            }

            if (is_array($entry) && isset($entry['title']) && is_string($entry['title'])) {
                $out[] = $entry['title'];
            }
        }

        return $out;
    }
}
