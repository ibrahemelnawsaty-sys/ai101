<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * What a support ticket is about (D-124). It helps whoever reads it; it never
 * changes the route the ticket takes.
 *
 * @see D-124 · PROJECT-CONTRACT §3
 */
enum SupportTicketCategory: string
{
    use HasEnumValues;

    case Account = 'account';
    case Platform = 'platform';
    case Program = 'program';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.support_ticket_category.'.$this->value);
    }
}
