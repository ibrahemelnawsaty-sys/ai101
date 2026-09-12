<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\ScheduledNotices;
use App\Services\Time\Clock;
use Illuminate\Console\Command;

/**
 * The every-minute pass that sends the time-driven notices of PRD §9.16.1: a
 * session tomorrow, in an hour, starting now; an assignment due in two days
 * and in six hours, to those who have not handed it in.
 *
 * Idempotent: each notice is claimed once in `scheduled_notices`, so a second
 * run in the same minute — or a run that overlaps a slow one — sends nothing
 * twice. See App\Services\Notifications\ScheduledNotices.
 *
 * @see PRD §9.10, §9.16.1 · FR-NOTIF-10, FR-NOTIF-11, FR-NOTIF-14 · D-83
 */
final class SendScheduledNotices extends Command
{
    /** @var string */
    protected $signature = 'athar:send-reminders';

    /** @var string */
    protected $description = 'Send the session and assignment reminders that are due now.';

    public function handle(ScheduledNotices $notices): int
    {
        $sent = $notices->run(Clock::now());

        // Language neutral: read in a cron log, not by a participant.
        $this->components->info(sprintf(
            '%d session notice(s), %d assignment notice(s).',
            $sent['sessions'],
            $sent['assignments'],
        ));

        return self::SUCCESS;
    }
}
