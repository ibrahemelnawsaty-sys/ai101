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

    /**
     * Whether the general supervisor may still seat an existing participant
     * in the cohort (D-84, D-117): a finished cohort has nothing left to join.
     * One answer for the request that refuses the write and for the button
     * that is not drawn.
     */
    public function seatable(): bool
    {
        return $this !== self::Completed;
    }
}
