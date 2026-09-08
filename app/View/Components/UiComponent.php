<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Component;

/**
 * Shared machinery for the component library's view models.
 *
 * The component templates used to normalise their own props inside a raw PHP
 * island - clamping a variant to the allowed list, minting an id, reading a
 * validation message off the error bag. Article 13 item 13 does not allow a
 * template to decide any of that, so every component in the library now has a
 * class beside it and the template only renders.
 *
 * Nothing here is authorisation and nothing here validates: the server already
 * decided both before the view was reached (CONSTITUTION art. 5).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 5, 13, 17, 18
 */
abstract class UiComponent extends Component
{
    /**
     * Clamp a prop to the variants a component actually draws.
     *
     * @param  list<string>  $allowed
     */
    protected static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * A stable DOM id: derived from the field name when there is one, random
     * when there is not, so two unnamed instances on one page never collide.
     */
    protected static function fieldId(string $prefix, ?string $name, ?string $id): string
    {
        if (is_string($id) && $id !== '') {
            return $id;
        }

        if ($name !== null && $name !== '') {
            return $prefix.'-'.Str::slug(str_replace(['[', ']', '.'], '-', $name));
        }

        return $prefix.'-'.Str::random(6);
    }

    /**
     * The validation message for a field.
     *
     * An explicit `error` prop wins; otherwise the shared error bag is read.
     * The bag is the server's answer (art. 5) - the view never decides whether
     * something is valid, it only shows what the server already said.
     */
    protected static function errorFor(?string $name, ?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        if ($name === null || $name === '') {
            return null;
        }

        $bag = view()->shared('errors');

        return $bag instanceof ViewErrorBag && $bag->has($name) ? $bag->first($name) : null;
    }

    /**
     * An aria-describedby list with the absent ids dropped.
     */
    protected static function describedBy(?string ...$ids): string
    {
        return implode(' ', array_filter($ids, static fn (?string $id): bool => $id !== null && $id !== ''));
    }

    /**
     * A value shared into every view by middleware, or null when absent.
     */
    protected static function shared(string $key): mixed
    {
        return view()->shared($key);
    }

    /**
     * A repeated-row prop as the templates iterate it.
     *
     * Blade hands a component whatever the template wrote, which is why these
     * props are declared `mixed`: `items="x"` without a colon arrives as a
     * string, and a controller may pass a Collection or a ragged array. A row
     * that is not an array is dropped rather than half-rendered - fail safe in
     * the chrome, never fail loud (art. 7).
     *
     * @return list<array<string, mixed>>
     */
    protected static function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $keyed = [];

            foreach ($row as $key => $cell) {
                $keyed[(string) $key] = $cell;
            }

            $rows[] = $keyed;
        }

        return $rows;
    }

    /**
     * A name-to-count prop, the shape the badge counters are read in.
     *
     * Same `mixed` boundary as rows(): a counter that is not a number is worth
     * nothing to the badge, so it counts as zero instead of rendering garbage.
     *
     * @return array<string, int>
     */
    protected static function counts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $counts = [];

        foreach ($value as $key => $count) {
            $counts[(string) $key] = is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }
}
