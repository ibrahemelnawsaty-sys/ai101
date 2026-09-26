<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * One line of a support ticket's timeline (D-124).
 *
 * `reply` is the participant's; `message` is the coordinator's word to the
 * participant; `note` is anyone else's line — shown to the participant or
 * kept internal, as its author chose. The moves between levels (`escalated`,
 * `returned`) are always shown to the participant; a handover between two
 * coordinators (`assigned`) never is, since "with the coordinator" did not
 * change (a temporary assumption D-124 records).
 *
 * @see D-124 · PROJECT-CONTRACT §3
 */
enum SupportTicketEntryType: string
{
    use HasEnumValues;

    case Opened = 'opened';
    case Reply = 'reply';
    case Message = 'message';
    case Note = 'note';
    case Escalated = 'escalated';
    case Returned = 'returned';
    case Assigned = 'assigned';
    case Resolved = 'resolved';
    case Reopened = 'reopened';
    case Closed = 'closed';
    case AutoClosed = 'auto_closed';

    public function label(): string
    {
        return __('enums.support_ticket_entry_type.'.$this->value);
    }

    /** A move between levels, which the participant is always told about. */
    public function movesLevel(): bool
    {
        return $this === self::Escalated || $this === self::Returned;
    }
}
