<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * State of a user enrollment inside a cohort.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum EnrollmentStatus: string
{
    use HasEnumValues;

    case Pending = 'pending';
    case Active = 'active';
    case Withdrawn = 'withdrawn';
    case Completed = 'completed';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.enrollment_status.'.$this->value);
    }
}
