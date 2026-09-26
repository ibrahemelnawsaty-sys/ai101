<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportTicketLevel;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketEntry;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;
use App\Services\Tickets\TicketRouting;
use App\Services\Tickets\TicketWorkflow;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;

/**
 * Support tickets (D-124). An allow-list: what the owner
 * granted, and nothing else (art. 22).
 *
 *  · the participant: opens a ticket, reads their own (never its internal
 *    lines), answers it and closes it until it is closed;
 *  · the coordinator holding it: writes to the participant or internally,
 *    marks it resolved, moves it up to the general supervisor — and, the
 *    primary coordinator only, hands it to another coordinator of the cohort;
 *  · every other coordinator of the cohort: reads it, and nothing more;
 *  · the general supervisor: reads every ticket and adds internal notes to
 *    any; acts only once it reached them — note, move up to the system
 *    administrator, return it to a coordinator of the cohort;
 *  · the system administrator: reads what reached them; acts only while it is
 *    with them — note, return it to the general supervisor;
 *  · the trainer: nothing.
 *
 * Every write is refused during an account preview (BR-33), and nothing is
 * written to a ticket that is closed or whose day after "resolved" ran out.
 * TicketWorkflow asks the same questions again under the row lock.
 *
 * @see D-124 · BR-22, BR-23, BR-28, BR-33 · CONSTITUTION art. 5, art. 22
 */
final class SupportTicketPolicy
{
    use InteractsWithScope;

    /**
     * Nothing during an account preview (D-125, open): a preview shows the
     * previewed account's screens, and a general supervisor's would hand the
     * previewing system administrator every ticket and its internal lines —
     * more than D-124 lets that role read. Until the owner decides, the
     * support tickets are closed to a preview, reading included (art. 7).
     */
    public function viewAny(User $user): bool
    {
        return ! ImpersonationContext::isActive()
            && $this->roles->hasAnyRole($user, ['participant', 'coordinator', 'admin', 'system_admin']);
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        if (ImpersonationContext::isActive()) {
            return false;
        }

        return ($this->roles->isActive($user) && $this->routing()->opened($user, $ticket))
            || $this->routing()->readsAsStaff($user, $ticket);
    }

    /**
     * Only a participant opens a ticket (D-124) — one who sits in a cohort,
     * active or completed. A ticket reaches that cohort's coordinator; an
     * account with no cohort (withdrawn, rejected, never seated) would open
     * one nobody could ever resolve, since only the coordinator may.
     */
    public function create(User $user): bool
    {
        return $this->writesAllowed()
            && $this->roles->isActive($user)
            && $this->roles->participantCohortIds($user) !== [];
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        return $this->writesAllowed()
            && $this->roles->isActive($user)
            && $this->routing()->opened($user, $ticket)
            && $this->stillOpen($ticket);
    }

    public function close(User $user, SupportTicket $ticket): bool
    {
        return $this->reply($user, $ticket);
    }

    /**
     * Whoever holds it; and the general supervisor, internally, on any ticket
     * — a closed one included, where nobody holds it any more (D-124).
     */
    public function note(User $user, SupportTicket $ticket): bool
    {
        if (! $this->writesAllowed()) {
            return false;
        }

        $follows = $this->admin($user) && $this->routing()->readsAsStaff($user, $ticket);

        if (! $this->stillOpen($ticket)) {
            return $follows;
        }

        return $this->routing()->holds($user, $ticket) || $follows;
    }

    /**
     * May this account write to the participant (not only internally)? The
     * coordinator holding it, and only them (D-126, open): the permissions
     * table grants "a visible or internal note" to the coordinator alone, the
     * solution reaches the participant through the coordinator alone, and the
     * system administrator writes to the general supervisor alone (D-118). A
     * line from a level above stays with the team until the owner decides.
     */
    public function writeToParticipant(User $user, SupportTicket $ticket): bool
    {
        return $this->writesAllowed()
            && $this->stillOpen($ticket)
            && $ticket->level === SupportTicketLevel::Coordinator
            && $this->routing()->holds($user, $ticket);
    }

    /** "Resolved" — the coordinator holding it, and only them. */
    public function resolve(User $user, SupportTicket $ticket): bool
    {
        return $this->writesAllowed()
            && $this->stillOpen($ticket)
            && $ticket->level === SupportTicketLevel::Coordinator
            && $ticket->status->isBeingHandled()
            && $this->routing()->holds($user, $ticket);
    }

    public function escalate(User $user, SupportTicket $ticket): bool
    {
        return $this->writesAllowed()
            && $this->stillOpen($ticket)
            && $ticket->level->above() !== null
            && $ticket->status->isBeingHandled()
            && $this->routing()->holds($user, $ticket);
    }

    public function returnDown(User $user, SupportTicket $ticket): bool
    {
        return $this->writesAllowed()
            && $this->stillOpen($ticket)
            && $ticket->level->below() !== null
            && $ticket->status->isBeingHandled()
            && $this->routing()->holds($user, $ticket);
    }

    /** The primary coordinator hands a ticket they hold to another coordinator. */
    public function assign(User $user, SupportTicket $ticket): bool
    {
        return $this->writesAllowed()
            && $this->stillOpen($ticket)
            && $ticket->level === SupportTicketLevel::Coordinator
            && $ticket->status->isBeingHandled()
            && $this->routing()->holds($user, $ticket)
            && $this->routing()->isPrimaryCoordinator($user, $ticket)
            && count($this->routing()->coordinatorIds($ticket)) > 1;
    }

    /**
     * A file on one line of the ticket: whoever reads the ticket, and — for a
     * line kept internal — the support staff alone.
     */
    public function viewAttachment(User $user, SupportTicket $ticket, SupportTicketAttachment $attachment): bool
    {
        if (! $this->view($user, $ticket)) {
            return false;
        }

        $entry = SupportTicketEntry::query()->find($attachment->support_ticket_entry_id);

        if (! $entry instanceof SupportTicketEntry || (string) $entry->support_ticket_id !== (string) $ticket->getKey()) {
            return false;
        }

        return ! $entry->is_internal || $this->routing()->readsAsStaff($user, $ticket);
    }

    private function stillOpen(SupportTicket $ticket): bool
    {
        return ! TicketWorkflow::isClosedAt($ticket, Clock::now());
    }

    private function routing(): TicketRouting
    {
        return app(TicketRouting::class);
    }
}
