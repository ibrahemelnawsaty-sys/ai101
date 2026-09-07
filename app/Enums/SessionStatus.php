<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Lifecycle state of a scheduled session.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum SessionStatus: string
{
    use HasEnumValues;

    case Scheduled = 'scheduled';
    case Live = 'live';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.session_status.'.$this->value);
    }
}
