<?php

declare(strict_types=1);

namespace App\Presenters\Tickets;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Presenters\Concerns\PresentsPeople;
use App\Services\Tickets\TicketWorkflow;
use App\Services\Time\RiyadhFormatter;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * One ticket in the support list (D-124). The participant reads where it is
 * by role; the support team also reads who opened it and in which cohort.
 *
 * A resolved ticket whose day ran out reads "closed" before the scheduled pass
 * closes its row: the boundary is the clock's (TicketWorkflow::isClosedAt).
 *
 * @see D-124 · PROJECT-CONTRACT §16
 */
final class TicketRow extends ViewModel
{
    use PresentsPeople;

    public static function from(SupportTicket $ticket, bool $staffView, CarbonImmutable $now): self
    {
        $formatter = app(RiyadhFormatter::class);
        $closed = TicketWorkflow::isClosedAt($ticket, $now);
        $status = $closed ? SupportTicketStatus::Closed : $ticket->status;
        $cohort = self::related($ticket, 'cohort');

        return new self([
            'id' => (string) $ticket->getKey(),
            'number' => (string) $ticket->number,
            'subject' => (string) $ticket->subject,
            'href' => route('support.show', $ticket),
            'category' => $ticket->category->label(),
            'statusLabel' => $status->label(),
            'statusVariant' => $status->variant(),
            'where' => $closed ? null : (string) __('support.at', ['level' => $ticket->level->label()]),
            'opener' => $staffView ? self::personName(self::related($ticket, 'opener')) : null,
            'cohort' => $staffView && $cohort !== null ? (string) $cohort->getAttribute('name') : null,
            'updated' => (string) __('support.last_activity', ['when' => $formatter->dateTime($ticket->last_activity_at)]),
            'updatedIso' => $formatter->iso($ticket->last_activity_at),
        ]);
    }
}
