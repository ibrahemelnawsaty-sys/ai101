<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Events\SupportTicketLetter;
use App\Models\SupportTicket;
use App\Services\Notifications\InAppNotifier;
use Illuminate\Support\Facades\Route;

/**
 * Who hears about a support ticket, and how (D-124).
 *
 * The participant, on the platform and by e-mail, at the owner's four moments:
 * the ticket is received (with its number), it moves between levels, it is
 * resolved, it is closed. A coordinator's message reaches them on the platform
 * only — the owner's answer. A handover between two coordinators is not a
 * move they are told about: "with the coordinator" did not change.
 *
 * The support staff — a temporary assumption D-124 records — on the platform
 * and by e-mail, when a ticket reaches them and when the participant answers
 * one they hold. Every channel follows the person's own preferences
 * (InAppNotifier, MailPreferences).
 *
 * Called after the change is committed: the ticket stands whether or not a
 * notice can be written.
 *
 * @see D-124 · PRD §9.16 · D-66, D-68
 */
final class TicketNotices
{
    /** The participant's notices. */
    public const PARTICIPANT_TYPE = 'support_ticket';

    /** The support staff's notices. */
    public const TEAM_TYPE = 'support_ticket_team';

    public function __construct(
        private readonly InAppNotifier $notifier,
        private readonly TicketRouting $routing,
    ) {}

    /** Received: the participant's receipt, and the arrival to whoever it reached. */
    public function opened(SupportTicket $ticket): void
    {
        $this->toParticipant($ticket, 'opened', mail: true);
        $this->arrived($ticket);
    }

    /** Moved up or down a level: the participant is told where it is now. */
    public function moved(SupportTicket $ticket): void
    {
        $this->toParticipant($ticket, 'moved', mail: true);
        $this->arrived($ticket);
    }

    /** Handed to another coordinator: only the one who now holds it hears. */
    public function handedOver(SupportTicket $ticket): void
    {
        $this->arrived($ticket);
    }

    /** The coordinator wrote to the participant: on the platform only. */
    public function messaged(SupportTicket $ticket): void
    {
        $this->toParticipant($ticket, 'message', mail: false);
    }

    public function resolved(SupportTicket $ticket): void
    {
        $this->toParticipant($ticket, 'resolved', mail: true);
    }

    public function closed(SupportTicket $ticket): void
    {
        $this->toParticipant($ticket, 'closed', mail: true);
    }

    /** The participant answered: whoever holds it hears (reopened or not). */
    public function replied(SupportTicket $ticket, bool $reopened): void
    {
        $event = $reopened ? 'reopened' : 'replied';

        $this->toStaff($ticket, $event, 'replied');
    }

    private function arrived(SupportTicket $ticket): void
    {
        $this->toStaff($ticket, 'arrived', 'arrived');
    }

    private function toParticipant(SupportTicket $ticket, string $event, bool $mail): void
    {
        $values = $this->values($ticket);
        $recipient = (string) $ticket->opener_id;

        $this->notifier->notify(
            [$recipient],
            self::PARTICIPANT_TYPE,
            (string) __('support.notices.'.$event.'.title', $values),
            (string) __('support.notices.'.$event.'.body', $values),
            $this->link($ticket),
        );

        if ($mail) {
            SupportTicketLetter::dispatch($recipient, (string) $ticket->getKey(), $event, self::PARTICIPANT_TYPE, $values);
        }
    }

    private function toStaff(SupportTicket $ticket, string $event, string $letter): void
    {
        $people = $this->routing->peopleAt($ticket);

        if ($people === []) {
            return;
        }

        $values = $this->values($ticket);

        $this->notifier->notify(
            $people,
            self::TEAM_TYPE,
            (string) __('support.notices.'.$event.'.title', $values),
            (string) __('support.notices.'.$event.'.body', $values),
            $this->link($ticket),
        );

        foreach ($people as $person) {
            SupportTicketLetter::dispatch($person, (string) $ticket->getKey(), $letter, self::TEAM_TYPE, $values);
        }
    }

    /**
     * @return array<string, string|int>
     */
    private function values(SupportTicket $ticket): array
    {
        $hours = TicketWorkflow::autoCloseHours();

        return [
            'number' => (string) $ticket->number,
            'subject' => (string) $ticket->subject,
            'level' => $ticket->level->label(),
            'hours' => trans_choice('support.count.hours', $hours, ['count' => $hours]),
        ];
    }

    private function link(SupportTicket $ticket): ?string
    {
        return Route::has('support.show') ? route('support.show', $ticket) : null;
    }
}
