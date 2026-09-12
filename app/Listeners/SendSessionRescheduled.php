<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SessionRescheduled;
use App\Mail\AtharLetter;
use App\Models\User;
use App\Services\Mail\CohortAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a cohort a session has moved, and to when.
 *
 * The roster is resolved when the queue runs, not when the trainer saved
 * (D-51); a trainee who switched this letter off is skipped (D-66).
 *
 * @see PRD §9.8, §9.16.1 · FR-NOTIF-12 · D-51, D-83
 */
final class SendSessionRescheduled implements ShouldQueue
{
    public function __construct(private readonly CohortAudience $audience) {}

    public function handle(SessionRescheduled $event): void
    {
        foreach ($this->audience->reachable($event->cohortId, 'session_changed') as $user) {
            $this->writeTo($user, $event);
        }
    }

    private function writeTo(User $user, SessionRescheduled $event): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send(new AtharLetter(
                copyKey: 'emails.session_changed',
                values: [
                    'session' => $event->sessionTitle,
                    'datetime' => $event->newTimeLabel,
                ],
                ctaUrl: route('schedule'),
            ));
        } catch (\Throwable $exception) {
            Log::warning('mail.session_rescheduled_failed', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
