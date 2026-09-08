<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Response;

/**
 * The one way this platform writes a CSV.
 *
 * UTF-8 with a byte-order mark, because a spreadsheet opened in Riyadh must
 * show Arabic and not mojibake; CRLF line endings, because that is what those
 * spreadsheets expect; every cell quoted and its quotes doubled, so a name or a
 * piece of feedback containing a comma cannot shift a column.
 *
 * Digits stay Latin — the platform has no Arabic-Indic digits anywhere
 * (CONSTITUTION Art. 15).
 *
 * @see PRD §4.2 (export to Excel) · CONSTITUTION Art. 6, Art. 15
 */
trait ExportsCsv
{
    /**
     * The row container is only ever walked with foreach, so it does not have
     * to be a list: callers build it with `->values()->all()`, which is a list
     * at run time but types as `array<int, ...>`. Each row still has to be a
     * list<string>, because csvRow() maps over it positionally.
     *
     * @param  list<string>  $headings
     * @param  array<int, list<string>>  $rows
     */
    protected function csvResponse(array $headings, array $rows, string $filename): Response
    {
        $lines = ["\u{FEFF}".$this->csvRow($headings)];

        foreach ($rows as $row) {
            $lines[] = $this->csvRow($row);
        }

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  list<string>  $cells
     */
    protected function csvRow(array $cells): string
    {
        return implode(',', array_map(
            static fn (string $cell): string => '"'.str_replace('"', '""', $cell).'"',
            $cells,
        ));
    }
}
