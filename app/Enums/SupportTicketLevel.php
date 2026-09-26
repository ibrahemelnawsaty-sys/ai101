<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Which level of the support team holds a ticket now (D-124). A ticket climbs
 * one level at a time — the cohort's coordinator, the general supervisor, the
 * system administrator — and comes back down the same way; no level is skipped.
 *
 * The participant is told the level, never a name.
 *
 * @see D-124 · PROJECT-CONTRACT §3
 */
enum SupportTicketLevel: string
{
    use HasEnumValues;

    case Coordinator = 'coordinator';
    case Admin = 'admin';
    case SystemAdmin = 'system_admin';

    public function label(): string
    {
        return __('enums.support_ticket_level.'.$this->value);
    }

    /** The level an escalation reaches, or null at the top. */
    public function above(): ?self
    {
        return match ($this) {
            self::Coordinator => self::Admin,
            self::Admin => self::SystemAdmin,
            self::SystemAdmin => null,
        };
    }

    /** The level a return reaches, or null at the bottom. */
    public function below(): ?self
    {
        return match ($this) {
            self::Coordinator => null,
            self::Admin => self::Coordinator,
            self::SystemAdmin => self::Admin,
        };
    }
}
