<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tickets\TicketWorkflow;
use App\Services\Time\Clock;
use Illuminate\Console\Command;

/**
 * The every-minute pass over support tickets (D-124):
 *
 *  · a resolved ticket the participant neither answered nor closed within a
 *    day is closed, and the participant is told — at the minute it falls
 *    due, not up to fifteen minutes later;
 *  · a ticket whose coordinator can no longer act on it (removed from the
 *    cohort, suspended, moved to another role) goes back to the primary
 *    coordinator, or up to the general supervisor, so none waits on someone
 *    who will never open it.
 *
 * Idempotent: each ticket is asked again under its row lock, so a second run
 * in the same minute changes nothing twice.
 *
 * @see D-124 · BR-07 · CONSTITUTION art. 10
 */
final class SweepSupportTickets extends Command
{
    /** @var string */
    protected $signature = 'athar:support-tickets';

    /** @var string */
    protected $description = 'Close resolved support tickets whose day ran out, and rehome tickets whose coordinator cannot act.';

    public function handle(TicketWorkflow $workflow): int
    {
        $now = Clock::now();

        $closed = $workflow->closeDue($now);
        $rehomed = $workflow->rehome($now);

        // Language neutral: read in a cron log, not by a participant.
        $this->components->info(sprintf('%d ticket(s) closed, %d ticket(s) rehomed.', $closed, $rehomed));

        return self::SUCCESS;
    }
}
