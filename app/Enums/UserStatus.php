<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Lifecycle state of a user account.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum UserStatus: string
{
    use HasEnumValues;

    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.user_status.'.$this->value);
    }
}
