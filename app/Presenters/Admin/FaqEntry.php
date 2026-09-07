<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Support\ViewModel;

/**
 * One question and answer of the landing FAQ.
 *
 * The FAQ is a JSON column, not a table, so an entry is addressed by the key
 * the server generated beside it — never by a position in the list, which would
 * shift the moment somebody deletes an entry (PRD §9.1).
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · CONSTITUTION art. 24
 */
final class FaqEntry extends ViewModel
{
    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $key = $stored['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        return new self([
            'id' => $key,
            'question' => is_string($stored['question'] ?? null) ? $stored['question'] : '',
            'answer' => is_string($stored['answer'] ?? null) ? $stored['answer'] : '',
        ]);
    }

    /**
     * Every entry of a `faq` JSON column, in stored order.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function collection(mixed $faq): \Illuminate\Support\Collection
    {
        $entries = [];

        if (is_array($faq)) {
            foreach ($faq as $row) {
                if (is_array($row)) {
                    $entry = self::fromStored($row);

                    if ($entry instanceof self) {
                        $entries[] = $entry;
                    }
                }
            }
        }

        return collect($entries);
    }
}
