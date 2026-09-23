<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * How a training session is delivered.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum SessionDeliveryMode: string
{
    use HasEnumValues;

    case Online = 'online';
    case InPerson = 'in_person';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.session_delivery_mode.'.$this->value);
    }
}
