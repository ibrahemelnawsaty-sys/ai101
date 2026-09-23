<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * What a participant is asking to be excused for: an automatic absence, or a
 * check-in the self-check-in window classified as late without an excuse.
 *
 * @see D-106
 */
enum AttendanceExceptionType: string
{
    use HasEnumValues;

    case Absence = 'absence';
    case Lateness = 'lateness';

    public function label(): string
    {
        return __('enums.attendance_exception_type.'.$this->value);
    }
}
