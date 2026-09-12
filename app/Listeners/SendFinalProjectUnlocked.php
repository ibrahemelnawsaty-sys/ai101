<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FinalProjectUnlocked;
use App\Mail\AtharLetter;
use App\Models\User;
use App\Services\Mail\CohortAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a cohort the final project is open.
 *
 * Half of the marks live in it (BR-11), so this is not an announcement anybody
 * should be left to discover by opening a tab.
 *
 * The roster is resolved HERE rather than carried by the event: this listener
 * is queued, and the people actively enrolled when it runs are the correct
 * audience — not the ones who were enrolled when the trainer pressed the
 * button. See App\Services\Mail\CohortAudience.
 *
 * @see BR-11 · PRD §9.13, §9.16.1 · D-51
 */
final class SendFinalProjectUnlocked implements ShouldQueue
{
    public function __construct(private readonly CohortAudience $audience) {}

    public function handle(FinalProjectUnlocked $event): void
    {
        foreach ($this->audience->reachable($event->cohortId, 'final_project_unlocked') as $user) {
            $this->writeTo($user, $event);
        }
    }

    private function writeTo(User $user, FinalProjectUnlocked $event): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send(new AtharLetter(
                copyKey: 'emails.final_project_unlocked',
                values: [
                    'datetime' => $event->deadline,
                ],
                ctaUrl: route('finalProject'),
                // `project.deadline` does not exist in either locale.
                // `assignments.deadline` does, and says exactly this (D-62).
                meta: ['assignments.deadline' => $event->deadline],
            ));
        } catch (\Throwable $exception) {
            // One unreachable address must not stop the rest of the cohort
            // from being told.
            Log::warning('mail.final_project_unlocked_failed', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
