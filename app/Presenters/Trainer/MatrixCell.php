<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\AttendanceStatus;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One square of the cohort attendance matrix.
 *
 * Colour alone never carries meaning (art. 18), so every cell carries three
 * things: a variant for the colour, a one-letter code that is readable without
 * it, and the full status as the cell's title for a screen reader.
 *
 * A session with no row for this person is `none`, not `absent`: the absence is
 * written by the reconciliation job, and until it has run the matrix says what
 * is true rather than what it expects (BR-08).
 *
 * @see BR-08, BR-09, BR-23 · PRD §9.9.7 · CONSTITUTION art. 18
 */
final class MatrixCell extends ViewModel
{
    use PresentsVariants;

    /**
     * The key to the letters: every status the grid can show, with its code, its
     * words and its variant — read from the SAME `of()` that draws a cell, so the key
     * cannot say something the cells do not. A `title` alone is not a key: a
     * touch screen has no hover.
     *
     * @return list<array{code: string, label: string, variant: string}>
     */
    public static function legend(): array
    {
        $cells = array_map(static fn (AttendanceStatus $status): self => self::of($status), AttendanceStatus::cases());
        $cells[] = self::of(null);

        return array_map(static fn (self $cell): array => [
            'code' => (string) $cell['shortCode'],
            'label' => (string) $cell['statusLabel'],
            'variant' => (string) $cell['variant'],
        ], $cells);
    }

    public static function of(?AttendanceStatus $status): self
    {
        return new self([
            'shortCode' => __('attendance.matrix.short.'.($status->value ?? 'none')),
            'statusLabel' => $status?->label() ?? __('attendance.status.not_recorded'),
            'variant' => $status === null ? 'none' : self::attendanceVariantOf($status),
        ]);
    }
}
