<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserStatus;
use App\Events\SupportTicketLetter;
use App\Mail\AtharLetter;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Mail\MailPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The support ticket letters (D-124): to the participant when the ticket is
 * received, moves between levels, is resolved and is closed — the owner's four
 * — and, a temporary assumption D-124 records, to the support staff a ticket
 * reaches or is answered on.
 *
 * Read again when the queue runs: an account suspended since, one that can no
 * longer read the ticket, or one that switched the letter off, gets nothing.
 *
 * @see D-124 · PRD §9.16 · D-62, D-66
 */
final class SendSupportTicketLetter implements ShouldQueue
{
    public const LETTERS = ['opened', 'moved', 'resolved', 'closed', 'arrived', 'replied'];

    public function __construct(private readonly MailPreferences $preferences) {}

    public function handle(SupportTicketLetter $event): void
    {
        $recipient = User::query()->find($event->recipientId);
        $ticket = SupportTicket::query()->find($event->ticketId);

        if (! $recipient instanceof User
            || ! $ticket instanceof SupportTicket
            || $recipient->status !== UserStatus::Active
            || ! $recipient->can('view', $ticket)) {
            return;
        }

        $address = (string) $recipient->getAttribute('email');

        if ($address === '' || ! $this->preferences->allows($recipient, $event->preferenceType)) {
            return;
        }

        $link = route('support.show', $ticket);

        try {
            $letter = match ($event->letter) {
                'opened' => new AtharLetter(copyKey: 'emails.support_ticket_opened', values: $event->values, ctaUrl: $link),
                'moved' => new AtharLetter(copyKey: 'emails.support_ticket_moved', values: $event->values, ctaUrl: $link),
                'resolved' => new AtharLetter(copyKey: 'emails.support_ticket_resolved', values: $event->values, ctaUrl: $link),
                'closed' => new AtharLetter(copyKey: 'emails.support_ticket_closed', values: $event->values, ctaUrl: $link),
                'arrived' => new AtharLetter(copyKey: 'emails.support_ticket_arrived', values: $event->values, ctaUrl: $link),
                'replied' => new AtharLetter(copyKey: 'emails.support_ticket_replied', values: $event->values, ctaUrl: $link),
                default => null,
            };

            if ($letter !== null) {
                Mail::to($address)->send($letter);
            }
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the ticket.
            // Logged without the address (art. 12), never rethrown.
            Log::warning('mail.support_ticket_failed', [
                'user_id' => $event->recipientId,
                'letter' => $event->letter,
                'exception' => $exception::class,
            ]);
        }
    }
}
