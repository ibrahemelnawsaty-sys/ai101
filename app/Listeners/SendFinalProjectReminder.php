<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FinalProjectReminderDue;
use App\Mail\AtharLetter;
use App\Models\FinalProject;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Mail\CohortAudience;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Writes "the final project closes soon" to each participant who has not
 * handed it in (D-122). The e-mail half of the reminder; the platform half is
 * written by ScheduledNotices at the tick, through InAppNotifier.
 *
 * Resolved at SEND time, all of it: whether the project is still open and
 * still ahead of its deadline, who has still not handed in, who switched this
 * letter off (D-66), and how long is left — in words (D-68).
 *
 * @see PRD §9.14, §9.16.1 · D-51, D-66, D-68, D-83, D-122
 */
final class SendFinalProjectReminder implements ShouldQueue
{
    public function __construct(private readonly CohortAudience $audience) {}

    public function handle(FinalProjectReminderDue $event): void
    {
        $now = Clock::now();

        $project = FinalProject::query()
            ->where('cohort_id', $event->cohortId)
            ->whereKey($event->projectId)
            ->where('is_unlocked', true)
            ->first();

        // Closed again, or past its deadline by the time the queue ran: a
        // reminder now would urge a door that is shut.
        if (! $project instanceof FinalProject || $project->isPastDueAt($now)) {
            return;
        }

        $left = Present::durationLabel(CarbonImmutable::parse($event->dueAtIso), $now);

        foreach ($this->audience->yetToHandInProject($event->cohortId, $event->projectId, 'final_project_due_reminder') as $user) {
            $this->writeTo($user, $event, $left);
        }
    }

    private function writeTo(User $user, FinalProjectReminderDue $event, string $left): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send(new AtharLetter(
                copyKey: 'emails.final_project_due_reminder',
                values: [
                    'project' => $event->projectTitle,
                    'countdown' => $left,
                ],
                ctaUrl: route('finalProject'),
                meta: ['assignments.deadline' => $event->dueAtLabel],
            ));
        } catch (\Throwable $exception) {
            Log::warning('mail.final_project_reminder_failed', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
