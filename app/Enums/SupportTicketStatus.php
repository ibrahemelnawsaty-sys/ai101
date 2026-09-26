<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * The stages of a support ticket (D-124): open until someone handling it acts,
 * in progress while it is being worked on, resolved once the coordinator marks
 * it so — then closed by the participant, or by the platform a day later.
 *
 * @see D-124 · PROJECT-CONTRACT §3
 */
enum SupportTicketStatus: string
{
    use HasEnumValues;

    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return __('enums.support_ticket_status.'.$this->value);
    }

    /** Still with the support team: open or in progress. */
    public function isBeingHandled(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    /** The two pill colours are chosen once, here, not in a view. */
    public function variant(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::InProgress => 'brand',
            self::Resolved => 'success',
            self::Closed => 'neutral',
        };
    }
}
