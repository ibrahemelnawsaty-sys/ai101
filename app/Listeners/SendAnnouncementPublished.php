<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AnnouncementPublished;
use App\Mail\AtharLetter;
use App\Models\User;
use App\Services\Mail\CohortAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Writes a new announcement to every active participant of the cohort who
 * has not switched this letter off.
 *
 * @see PRD §9.13.1, §9.16.1 · FR-NOTIF-21 · D-51, D-83
 */
final class SendAnnouncementPublished implements ShouldQueue
{
    public function __construct(private readonly CohortAudience $audience) {}

    public function handle(AnnouncementPublished $event): void
    {
        foreach ($this->audience->reachable($event->cohortId, 'announcement_published') as $user) {
            $this->writeTo($user, $event);
        }
    }

    private function writeTo(User $user, AnnouncementPublished $event): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send(new AtharLetter(
                copyKey: 'emails.announcement_published',
                values: ['excerpt' => $event->excerpt],
                ctaUrl: route('messages.index', ['thread' => $event->threadId]),
            ));
        } catch (\Throwable $exception) {
            Log::warning('mail.announcement_failed', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
