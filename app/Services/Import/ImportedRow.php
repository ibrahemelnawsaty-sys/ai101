<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Http\Requests\Concerns\ProfileFieldRules;

/**
 * One line of the uploaded sheet, and what is wrong with it.
 *
 * It carries the row NUMBER as the administrator sees it in Excel, because an
 * error that says "row 34" has to mean the row they are looking at — not the
 * thirty-fourth element of a zero-based array with the header removed.
 *
 * @see PRD §4.2 · D-63
 */
final class ImportedRow
{
    use ProfileFieldRules;

    /**
     * @param  array<string, string>  $values  field => value, already tidied
     * @param  list<string>  $errors  human sentences, already translated
     */
    public function __construct(
        public readonly int $number,
        public readonly array $values,
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function email(): string
    {
        return (string) ($this->values['email'] ?? '');
    }

    /** The full Arabic name, for the preview table. */
    public function displayName(): string
    {
        return trim(implode(' ', array_filter([
            $this->values['first_name_ar'] ?? '',
            $this->values['father_name_ar'] ?? '',
            $this->values['grandfather_name_ar'] ?? '',
            $this->values['family_name_ar'] ?? '',
        ])));
    }

    /**
     * The values rewritten with `profiles` column names.
     *
     * The mapping is `ProfileFieldRules::toProfileColumns()`, the same one the
     * form uses — the sheet asks for `father_name_ar` and the column is called
     * `second_name_ar`, and a second copy of that translation would be a second
     * thing to keep in step.
     *
     * @return array<string, mixed>
     */
    public function profileColumns(): array
    {
        return $this->toProfileColumns($this->values);
    }
}
