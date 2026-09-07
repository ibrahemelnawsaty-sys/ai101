<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Lifecycle state of a cohort.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum CohortStatus: string
{
    use HasEnumValues;

    case Upcoming = 'upcoming';
    case Open = 'open';
    case Running = 'running';
    case Completed = 'completed';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.cohort_status.'.$this->value);
    }
}
