<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SessionCancelled;
use App\Mail\AtharLetter;
use App\Models\User;
use App\Services\Mail\CohortAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a cohort a session has been cancelled or moved, and why.
 *
 * This is the letter people forgive least when it does not arrive: somebody
 * travels to a session that is not happening.
 *
 * The roster is resolved HERE rather than carried by the event: this listener
 * is queued, and the people actively enrolled when it runs are the correct
 * audience — not the ones who were enrolled when the trainer pressed the
 * button. See App\Services\Mail\CohortAudience.
 *
 * @see PRD §9.8, §9.16.1 · D-51
 */
final class SendSessionCancelled implements ShouldQueue
{
    public function __construct(private readonly CohortAudience $audience) {}

    public function handle(SessionCancelled $event): void
    {
        foreach ($this->audience->reachable($event->cohortId, 'session_cancelled') as $user) {
            $this->writeTo($user, $event);
        }
    }

    private function writeTo(User $user, SessionCancelled $event): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send(new AtharLetter(
                // Its own words. It borrowed session_changed, whose subject says
                // the session "has moved" — sending people to look for a new
                // time that does not exist (D-77).
                copyKey: 'emails.session_cancelled',
                values: [
                    'session' => $event->sessionTitle,
                    'reason' => $event->reason,
                ],
                ctaUrl: route('schedule'),
            ));
        } catch (\Throwable $exception) {
            // One unreachable address must not stop the rest of the cohort
            // from being told.
            Log::warning('mail.session_changed_failed', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
