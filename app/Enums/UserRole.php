<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Platform role of a user account.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum UserRole: string
{
    use HasEnumValues;

    case Admin = 'admin';
    case Trainer = 'trainer';
    case Participant = 'participant';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.user_role.'.$this->value);
    }
}
