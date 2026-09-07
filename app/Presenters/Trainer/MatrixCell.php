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

    public static function of(?AttendanceStatus $status): self
    {
        return new self([
            'shortCode' => __('attendance.matrix.short.'.($status?->value ?? 'none')),
            'statusLabel' => $status?->label() ?? __('attendance.status.not_recorded'),
            'variant' => $status === null ? 'none' : self::attendanceVariantOf($status),
        ]);
    }
}
