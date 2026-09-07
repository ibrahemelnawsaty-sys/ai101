<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Publication state of an assignment.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum AssignmentStatus: string
{
    use HasEnumValues;

    case Draft = 'draft';
    case Published = 'published';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.assignment_status.'.$this->value);
    }
}
