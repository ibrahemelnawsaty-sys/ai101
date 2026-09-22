<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Where an excuse request stands. Terminal once decided — a decided request
 * is never reopened; the participant submits a new one if they need to.
 *
 * @see D-106
 */
enum AttendanceExceptionStatus: string
{
    use HasEnumValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('enums.attendance_exception_status.'.$this->value);
    }
}
