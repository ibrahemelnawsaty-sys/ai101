<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Role a user holds inside a specific cohort.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum EnrollmentRole: string
{
    use HasEnumValues;

    case Participant = 'participant';
    case Trainer = 'trainer';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.enrollment_role.'.$this->value);
    }
}
