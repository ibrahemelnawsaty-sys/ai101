<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\Gender;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * Reads a filled sheet and says, row by row, what is wrong with it.
 *
 * IT VALIDATES AND IT DOES NOT WRITE. Nothing here creates an account: the
 * administrator sees exactly what will happen before anything happens, and the
 * same reader runs again at confirm time — because the world moves between the
 * two requests, and an address that was free during the preview can be taken by
 * the time the button is pressed.
 *
 * THE RULES ARE THE FORM'S RULES. `ProfileFieldRules` is the single source, so
 * a row that this accepts is a row the single-account form would have accepted.
 * An import that is more permissive than the form is a second, quieter door
 * onto the same table.
 *
 * DUPLICATES ARE CHECKED TWICE, AGAINST TWO DIFFERENT THINGS. Against the
 * database, because the address may already have an account; and against the
 * rest of the file, because the same person pasted twice in one sheet passes
 * every database check and then fails on the unique index halfway through the
 * import, leaving half a cohort created.
 *
 * @see PRD §4.2, §9.2.1 · CONSTITUTION Art. 5, Art. 6 · D-63
 */
final class ParticipantImportReader
{
    use \App\Http\Requests\Concerns\ProfileFieldRules;

    /**
     * @return array<int, ImportedRow>
     */
    public function read(string $path, string $extension): array
    {
        $table = $this->rows($path, $extension);

        if ($table === []) {
            return [];
        }

        $map = $this->headerMap(array_shift($table));
        $rows = [];
        $seenEmails = [];
        $seenPhones = [];

        foreach ($table as $offset => $cells) {
            // +2: the header is row 1, and a person counting rows in Excel
            // starts at 1. An error that names row 34 has to mean row 34 in the
            // file they are looking at.
            $number = $offset + 2;
            $values = $this->extract($cells, $map);

            if ($this->isBlank($values)) {
                continue;
            }

            $errors = $this->errorsFor($values, $seenEmails, $seenPhones);

            $email = (string) ($values['email'] ?? '');
            $phone = (string) ($values['phone'] ?? '');

            if ($email !== '') {
                $seenEmails[$email] = $number;
            }

            if ($phone !== '') {
                $seenPhones[$phone] = $number;
            }

            $rows[] = new ImportedRow($number, $values, $errors);
        }

        return $rows;
    }

    /**
     * The sheet as a plain table.
     *
     * `IOFactory` picks the reader from the file itself. A CSV is told the
     * encoding explicitly rather than left to guess: an `.xlsx` carries its own
     * UTF-8 and a CSV does not, and a wrong guess turns every Arabic name into
     * question marks that then fail the name rule for a reason the
     * administrator cannot see.
     *
     * @return array<int, array<int, mixed>>
     */
    private function rows(string $path, string $extension): array
    {
        if (strtolower($extension) === 'csv') {
            $reader = new Csv;
            $reader->setInputEncoding(Csv::guessEncoding($path));
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } else {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        }

        $table = $book->getActiveSheet()->toArray(null, true, false, false);
        $book->disconnectWorksheets();

        return $table;
    }

    /**
     * Which column holds which field.
     *
     * Matched against the field name, the Arabic label and the English label,
     * so a sheet saved from either locale — or one whose headers were retyped
     * by hand — still lands in the right columns. Position is NOT trusted: an
     * administrator who reorders the columns would otherwise import every
     * person's phone number as their family name.
     *
     * @param  array<int, mixed>  $header
     * @return array<string, int> field => column index
     */
    private function headerMap(array $header): array
    {
        $wanted = [];

        foreach (ParticipantImportSheet::columns() as $field => $key) {
            $wanted[$field] = array_map(
                fn (string $text): string => $this->normalise($text),
                [$field, (string) __($key, [], 'ar'), (string) __($key, [], 'en')],
            );
        }

        $map = [];

        foreach ($header as $index => $cell) {
            $text = $this->normalise(is_scalar($cell) ? (string) $cell : '');

            if ($text === '') {
                continue;
            }

            foreach ($wanted as $field => $candidates) {
                if (! array_key_exists($field, $map) && in_array($text, $candidates, true)) {
                    $map[$field] = (int) $index;
                    break;
                }
            }
        }

        return $map;
    }

    /** Whitespace-collapsed and case-folded, so a stray space cannot lose a column. */
    private function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $map
     * @return array<string, string>
     */
    private function extract(array $cells, array $map): array
    {
        $values = [];

        foreach (ParticipantImportSheet::columns() as $field => $_) {
            $index = $map[$field] ?? null;
            $raw = $index === null ? null : ($cells[$index] ?? null);
            $text = is_scalar($raw) ? (string) $raw : '';

            $values[$field] = (string) ($this->tidy($text) ?? '');
        }

        // The same normalisation the form performs, so the two doors agree.
        $values['email'] = mb_strtolower($values['email']);
        $values['phone'] = (string) ($this->canonicalPhone($values['phone']) ?? $values['phone']);

        foreach (['first_name_en', 'father_name_en', 'grandfather_name_en', 'family_name_en'] as $field) {
            if ($values[$field] !== '') {
                $values[$field] = mb_convert_case($values[$field], MB_CASE_TITLE, 'UTF-8');
            }
        }

        return $values;
    }

    /** @param  array<string, string>  $values */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, int>  $seenEmails
     * @param  array<string, int>  $seenPhones
     * @return list<string>
     */
    private function errorsFor(array $values, array $seenEmails, array $seenPhones): array
    {
        $rules = [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => $this->phoneRules(),
            'gender' => ['required', \Illuminate\Validation\Rule::enum(Gender::class)],
        ];

        foreach ($this->arabicNameFields() as $field) {
            $rules[$field] = $this->arabicNameRules();
        }

        foreach ($this->latinNameFields() as $field) {
            $rules[$field] = $this->latinNameRules();
        }

        $validator = Validator::make($values, $rules, [], $this->attributeNames());
        $errors = array_values($validator->errors()->all());

        $email = $values['email'];
        $phone = $values['phone'];

        // Inside the file first: this one is invisible to any database check
        // and would otherwise fail halfway through, leaving half a cohort made.
        if ($email !== '' && array_key_exists($email, $seenEmails)) {
            $errors[] = (string) __('admin.users.import.errors.duplicate_in_file', ['row' => $seenEmails[$email]]);
        } elseif ($email !== '' && User::withTrashed()->where('email', $email)->exists()) {
            $errors[] = (string) __('admin.users.import.errors.email_taken');
        }

        if ($phone !== '' && array_key_exists($phone, $seenPhones)) {
            $errors[] = (string) __('admin.users.import.errors.duplicate_phone_in_file', ['row' => $seenPhones[$phone]]);
        } elseif ($phone !== '' && Profile::query()->where('phone', $phone)->exists()) {
            $errors[] = (string) __('admin.users.import.errors.phone_taken');
        }

        return $errors;
    }

    /**
     * Field names as the administrator sees them in the sheet, so a message
     * says "the mobile number" and not "phone".
     *
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        $names = [];

        foreach (ParticipantImportSheet::columns() as $field => $key) {
            $names[$field] = (string) __($key);
        }

        return $names;
    }
}
