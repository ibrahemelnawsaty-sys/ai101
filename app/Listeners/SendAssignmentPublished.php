<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AssignmentPublished;
use App\Mail\AtharLetter;
use App\Models\User;
use App\Services\Mail\CohortAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a cohort that new work has been set.
 *
 * The roster is resolved HERE rather than carried by the event: this listener
 * is queued, and the people actively enrolled when it runs are the correct
 * audience — not the ones who were enrolled when the trainer pressed the
 * button. See App\Services\Mail\CohortAudience.
 *
 * @see BR-11 · PRD §9.11, §9.16.1 · D-51
 */
final class SendAssignmentPublished implements ShouldQueue
{
    public function __construct(private readonly CohortAudience $audience) {}

    public function handle(AssignmentPublished $event): void
    {
        foreach ($this->audience->reachable($event->cohortId) as $user) {
            $this->writeTo($user, $event);
        }
    }

    private function writeTo(User $user, AssignmentPublished $event): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send(new AtharLetter(
                copyKey: 'emails.assignment_published',
                values: [
                    'assignment' => $event->assignmentTitle,
                    'max' => $event->maxScore,
                    'datetime' => $event->dueAt,
                ],
                ctaUrl: $event->url,
                // The key, not the resolved label: `assignments.due` is a
                // GROUP, and casting it to string raised a warning Laravel
                // throws and the catch below swallowed — so this letter had
                // never been sent to anyone (D-62).
                meta: ['assignments.deadline' => $event->dueAt],
            ));
        } catch (\Throwable $exception) {
            // One unreachable address must not stop the rest of the cohort
            // from being told.
            Log::warning('mail.assignment_published_failed', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
