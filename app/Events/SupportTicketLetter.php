<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A support ticket letter to one person (D-124). Ids and values only: the
 * queued listener reads the account and the ticket again when it runs, and
 * sends nothing to someone who can no longer read it.
 *
 * `letter` is one of SendSupportTicketLetter::LETTERS; `values` are the
 * placeholders as they stood when the event happened (the level a ticket
 * moved to, not the one it may have reached since).
 */
final class SupportTicketLetter
{
    use Dispatchable;

    /**
     * @param  array<string, string|int>  $values
     */
    public function __construct(
        public readonly string $recipientId,
        public readonly string $ticketId,
        public readonly string $letter,
        public readonly string $preferenceType,
        public readonly array $values,
    ) {}
}
