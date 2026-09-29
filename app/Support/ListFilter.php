<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How a list screen reads its filters — one spelling for every screen.
 *
 * A filter is a string in the query string that can only NARROW a list the
 * screen already scoped (the participant's own rows, the trainer's cohort). So:
 *
 *   · a value that is not a string, or is empty, is "no filter";
 *   · a value the screen never offered — an enum case that does not exist, a
 *     week of another cohort — is ALSO "no filter": it is ignored, neither an
 *     error nor an empty result, so a stale bookmark still shows the list;
 *   · nothing here touches the database, so a screen cannot forget to scope
 *     its query by calling it (art. 22).
 *
 * @see CONSTITUTION art. 22 · D-136
 */
final class ListFilter
{
    /** Longest free-text search a filter carries. */
    public const MAX_TEXT = 100;

    /** A free-text search, trimmed, or null when there is none. */
    public static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_TEXT);
    }

    /**
     * The value when it is one of the offered ones, else null.
     *
     * @param  list<string>  $allowed
     */
    public static function oneOf(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * The value when it is a case of the enum, else null.
     *
     * @param  class-string<\BackedEnum>  $enum
     */
    public static function enum(Request $request, string $key, string $enum): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $case = $enum::tryFrom($value);

        return $case === null ? null : (string) $case->value;
    }

    /** The `LIKE` pattern for a free-text search. */
    public static function like(string $term): string
    {
        return '%'.$term.'%';
    }
}
