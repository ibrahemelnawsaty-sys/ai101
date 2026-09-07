<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Outcome of an attendance record for one session.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum AttendanceStatus: string
{
    use HasEnumValues;

    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Excused = 'excused';
    case Incomplete = 'incomplete';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.attendance_status.'.$this->value);
    }
}
