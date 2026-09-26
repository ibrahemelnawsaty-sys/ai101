<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

/**
 * How a letter reads its copy: a required line, an optional line, and the
 * detail strip — with the guards that keep a raw translation key out of a
 * letter (D-62). Moved out of AtharLetter unchanged so the receipt letter of
 * D-122 reads its copy the same way, rather than through a second copy of
 * these guards drifting on its own.
 *
 * The using class declares `$copyKey`, `$values` and `$meta`.
 *
 * @see PRD §9.16 · D-49, D-62, D-122
 */
trait ResolvesLetterCopy
{
    /** A required line: its absence is a copy bug, and an empty string shows it. */
    private function line(string $name): string
    {
        $key = $this->copyKey.'.'.$name;
        $text = __($key, $this->values);

        // `__()` hands back the key itself when nothing is translated. A visitor
        // must never read "emails.certificate_issued.subject".
        return is_string($text) && $text !== $key ? $text : '';
    }

    /** An optional line: many letters have no button and no note. */
    private function optionalLine(string $name): ?string
    {
        $text = $this->line($name);

        return $text === '' ? null : $text;
    }

    /**
     * The detail strip, with every label resolved here and nowhere else.
     *
     * A row is dropped rather than printed broken. A label that resolves to a
     * group, to nothing, or to its own key is a copy bug — and a copy bug must
     * not become a line of ASCII in the middle of an Arabic letter. Dropping
     * one row still delivers the letter; the alternative used to be no letter
     * at all.
     *
     * @return array<string, string>
     */
    private function metaRows(): array
    {
        $rows = [];

        foreach ($this->meta as $key => $value) {
            $label = __($key);

            // `__()` returns the key when nothing is translated, and the whole
            // array when the key names a group. Neither is a label.
            if (! is_string($label) || $label === '' || $label === $key) {
                continue;
            }

            // No is_scalar() guard: $meta is declared array<string, string> and
            // every caller honours it, so the guard could never be false and
            // PHPStan says so at level 8.
            $text = trim($value);

            if ($text === '') {
                continue;
            }

            $rows[$label] = $text;
        }

        return $rows;
    }
}
