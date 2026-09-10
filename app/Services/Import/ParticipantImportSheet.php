<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\Gender;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The sheet an administrator downloads, fills in, and uploads back.
 *
 * WHY .XLSX AND NOT A CSV
 * A CSV would be the obvious answer and it silently corrupts the one column
 * that cannot survive it. The corruption happens in EXCEL, not here: opening a
 * CSV, Excel drops the quoting and type-infers each cell, so `0512345678`
 * becomes the number 512345678 and is written back with nine digits. The
 * platform's phone rule is `/^(?:05[0-9]{8}|9665[0-9]{8})$/`, so every row then
 * fails with an error about the phone number, and the administrator has no way
 * to see why: the file they saved looks right on their screen. A byte-order
 * mark does not help; this is type inference, not encoding.
 *
 * THAT LAST PARAGRAPH IS DOCUMENTED, NOT MEASURED. It is Excel's behaviour, and
 * this project has no Excel to run: a round trip through PhpSpreadsheet's own
 * CSV reader keeps the zero, because that reader does not type-infer. The
 * warning on the upload screen is worded for the tool the administrator will
 * actually use, and the `9665…` guidance is what makes a CSV safe either way.
 *
 * An `.xlsx` cell can be declared `FORMAT_TEXT`, and the leading zero then
 * survives typing, saving, closing and reopening. `phpoffice/phpspreadsheet`
 * was already a dependency of this project and had no callers.
 *
 * The CSV download stays available for anyone who wants it, and its guidance
 * says to write the number in its `9665…` form, which has no leading zero to
 * lose and which `ProfileFieldRules::canonicalPhone()` already maps back.
 *
 * WHAT THE SHEET DOES NOT ASK FOR
 *   · `role` — every imported row is a participant. A column that could mint an
 *     administrator from a spreadsheet is a privilege-escalation surface, and
 *     role changes have their own audited endpoint that demands a written
 *     reason.
 *   · `status` — the account is created active.
 *   · `password` — the platform generates it. Nobody types a trainee's
 *     password, and nobody reads it back.
 *   · `cohort` — chosen once, on the upload screen. Sixty rows repeating the
 *     same cohort name is sixty chances to mistype it.
 *
 * THE EXAMPLE ROW LIVES ON THE SECOND SHEET. Not greyed out on the first with a
 * note saying "delete this line", because that line gets imported.
 *
 * @see PRD §4.2, §4.5.1 · CONSTITUTION Art. 6, Art. 15 · D-63
 */
final class ParticipantImportSheet
{
    /**
     * The columns, in the order `ProfileFieldRules` names them.
     *
     * The key is the field name the reader maps back to; the label comes from
     * the registration form's own copy, so the two forms cannot drift apart.
     *
     * @return array<string, string> field => label key
     */
    public static function columns(): array
    {
        return [
            'first_name_ar' => 'auth.register.first_name_ar',
            'father_name_ar' => 'auth.register.father_name_ar',
            'grandfather_name_ar' => 'auth.register.grandfather_name_ar',
            'family_name_ar' => 'auth.register.family_name_ar',
            'first_name_en' => 'auth.register.first_name_en',
            'father_name_en' => 'auth.register.father_name_en',
            'grandfather_name_en' => 'auth.register.grandfather_name_en',
            'family_name_en' => 'auth.register.family_name_en',
            'email' => 'auth.shared.email',
            'phone' => 'auth.register.phone',
            'gender' => 'auth.register.gender',
        ];
    }

    /** @return list<string> the visible header row */
    public static function headings(): array
    {
        return array_map(
            static fn (string $key): string => (string) __($key),
            array_values(self::columns()),
        );
    }

    /** The .xlsx bytes. */
    public function build(): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle((string) __('admin.users.import.title'));

        $this->fillDataSheet($book);
        $this->fillHelpSheet($book);

        $book->setActiveSheetIndex(0);

        $writer = new Xlsx($book);

        ob_start();
        $writer->save('php://output');
        $bytes = (string) ob_get_clean();

        $book->disconnectWorksheets();

        return $bytes;
    }

    private function fillDataSheet(Spreadsheet $book): void
    {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle((string) __('admin.users.import.data_sheet'));

        // Column A renders rightmost, which is where an Arabic reader starts.
        $sheet->setRightToLeft(true);

        $headings = self::headings();
        $sheet->fromArray($headings, null, 'A1');
        $sheet->getStyle('A1:'.$this->column(count($headings)).'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (array_keys(self::columns()) as $index => $field) {
            $letter = $this->column($index + 1);
            $sheet->getColumnDimension($letter)->setAutoSize(true);

            // The whole reason this is not a CSV.
            if ($field === 'phone') {
                $sheet->getStyle($letter.'2:'.$letter.((int) config('athar.invitations.import_max_rows', 200) + 1))
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);
            }

            if ($field === 'gender') {
                $this->addGenderList($sheet, $letter);
            }
        }
    }

    /**
     * A dropdown, so `gender` cannot be typed as a word the enum has never
     * heard of and then fail sixty rows at once.
     */
    private function addGenderList(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $letter): void
    {
        $allowed = implode(',', array_map(
            static fn (Gender $case): string => $case->value,
            Gender::cases(),
        ));

        $last = (int) config('athar.invitations.import_max_rows', 200) + 1;

        for ($row = 2; $row <= $last; $row++) {
            $validation = $sheet->getCell($letter.$row)->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setFormula1('"'.$allowed.'"');
        }
    }

    private function fillHelpSheet(Spreadsheet $book): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle((string) __('admin.users.import.help_sheet'));
        $sheet->setRightToLeft(true);

        $rows = [
            [(string) __('admin.users.import.help_title')],
            [''],
            [(string) __('admin.users.import.help_rules')],
            [''],
            // The example lives here, where it cannot be imported by accident.
            [(string) __('admin.users.import.help_example')],
            self::headings(),
            // The specimen names are copy, so they live in lang/ like every
            // other string. A file under app/ carrying Arabic fails G3 and G5,
            // and this exact mistake was caught by the gate one commit ago.
            self::exampleRow(),
        ];

        $sheet->fromArray($rows, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A6:'.$this->column(count(self::columns())).'6')->getFont()->setBold(true);

        foreach (array_keys(self::columns()) as $index => $field) {
            $sheet->getColumnDimension($this->column($index + 1))->setAutoSize(true);
        }
    }

    /**
     * A filled specimen row, from lang/, in the same column order.
     *
     * @return list<string>
     */
    private static function exampleRow(): array
    {
        $example = __('admin.users.import.example');

        if (! is_array($example)) {
            return [];
        }

        return array_map(
            static fn (string $field): string => (string) ($example[$field] ?? ''),
            array_keys(self::columns()),
        );
    }

    private function column(int $oneBased): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($oneBased);
    }
}
