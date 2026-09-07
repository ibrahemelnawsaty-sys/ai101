<?php

declare(strict_types=1);

namespace App\Presenters\Concerns;

use App\Services\Time\Clock;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Turning stored values into the strings an HTML form control expects.
 *
 * `<input type="date">` wants `2026-10-12`, `type="time"` wants `19:00` and
 * `type="datetime-local"` wants `2026-10-12T19:00` — and all three must be in
 * Riyadh wall time, because that is what the person typing them means and what
 * the FormRequest re-reads through Clock when it comes back (CONSTITUTION
 * art. 11). Storage stays UTC throughout; the conversion happens here and in
 * Clock, nowhere else.
 *
 * @see BR-07 · PRD §13 · CONSTITUTION art. 11 · PROJECT-CONTRACT §5
 */
trait PresentsFormValues
{
    /** `2026-10-12` in Riyadh, or an empty string when absent. */
    protected static function dateInput(mixed $at): string
    {
        return $at instanceof DateTimeInterface ? Clock::toRiyadh($at)->format('Y-m-d') : '';
    }

    /** `19:00` in Riyadh, or an empty string when absent. */
    protected static function timeInput(mixed $at): string
    {
        return $at instanceof DateTimeInterface ? Clock::toRiyadh($at)->format('H:i') : '';
    }

    /** `2026-10-12T19:00` in Riyadh, or an empty string when absent. */
    protected static function dateTimeInput(mixed $at): string
    {
        return $at instanceof DateTimeInterface ? Clock::toRiyadh($at)->format('Y-m-d\TH:i') : '';
    }

    /**
     * A wall-clock column stored as a plain `HH:MM:SS` string, trimmed to what
     * `<input type="time">` accepts. Sessions store their times this way
     * (PROJECT-CONTRACT §4), so there is nothing to convert — they are already
     * Riyadh wall time.
     */
    protected static function wallTimeInput(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        if (! is_string($value) || $value === '') {
            return '';
        }

        return preg_match('/^(\d{2}:\d{2})/', $value, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * A JSON list column rendered as one item per line, which is how every
     * "one per line" textarea on the admin screens is edited.
     */
    protected static function linesFrom(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value : '';
        }

        $lines = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $lines[] = trim($item);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Read a relation without triggering a lazy load.
     *
     * `Model::preventLazyLoading()` is on outside production (CONSTITUTION
     * art. 19), so a presenter that reached for an un-eager-loaded relation
     * would throw rather than quietly issuing an N+1. Asking first keeps the
     * presenter honest: a missing relation renders as absent, and the query
     * that should have loaded it is the thing to fix.
     */
    protected static function related(mixed $model, string $relation): mixed
    {
        if (! $model instanceof Model || ! $model->relationLoaded($relation)) {
            return null;
        }

        return $model->getRelation($relation);
    }

    /**
     * Read one attribute off a value that may or may not be a model, without
     * ever tripping a lazy load. Returns null when the value is absent, which
     * is exactly what a presenter wants: "no value" rather than an exception.
     */
    protected static function attr(mixed $model, string $attribute): mixed
    {
        return $model instanceof Model ? $model->getAttribute($attribute) : null;
    }

    /** The same, coerced to a string, with a caller-chosen placeholder. */
    protected static function text(mixed $model, string $attribute, string $absent = '—'): string
    {
        $value = self::attr($model, $attribute);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $absent;
    }
}
