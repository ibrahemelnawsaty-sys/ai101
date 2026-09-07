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
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.email_token_type.'.$this->value);
    }
}
