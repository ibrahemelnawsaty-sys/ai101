<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Purpose of a single-use email token.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum EmailTokenType: string
{
    use HasEnumValues;

    case Verify = 'verify';
    case Reset = 'reset';

    /**
     * An account an administrator created for someone: the link is the only way
     * into it, and following it is where the person chooses their password and
     * fills in what the invitation did not know (D-85).
     */
    case Invite = 'invite';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.email_token_type.'.$this->value);
    }
}
