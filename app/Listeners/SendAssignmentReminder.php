<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AssignmentStatus;
use App\Events\AssignmentReminderRequested;
use App\Mail\AtharLetter;
use App\Models\Assignment;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Mail\CohortAudience;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Writes "the deadline is near" to each participant who has not handed in.
 *
 * The e-mail half of "remind who has not submitted" (FR-ASGN-30). The in-app
 * half is written by the controller at the moment of the press, through
 * InAppNotifier, as every in-app notice is.
 *
 * Resolved at SEND time, all of it: who has still not submitted, whether the
 * assignment is still published and still open, and how long is left — in
 * words, so a letter never says "203:59:00 remains" (D-68).
 *
 * @see PRD §9.11.3, §9.16.1 · FR-ASGN-30 · D-51, D-66, D-68
 */
final class SendAssignmentReminder implements ShouldQueue
{
    public function __construct(private readonly CohortAudience $audience) {}

    public function handle(AssignmentReminderRequested $event): void
    {
        $now = Clock::now();

        $assignment = Assignment::query()
            ->where('cohort_id', $event->cohortId)
            ->whereKey($event->assignmentId)
            ->where('status', AssignmentStatus::Published->value)
            ->first();

        // Unpublished or past its deadline by the time the queue ran: a
        // reminder now would either leak a draft or urge a closed door.
        if (! $assignment instanceof Assignment || $assignment->isPastDueAt($now)) {
            return;
        }

        $left = Present::durationLabel(CarbonImmutable::parse($event->dueAtIso), $now);

        foreach ($this->audience->yetToSubmit($event->cohortId, $event->assignmentId, 'assignment_due_reminder') as $user) {
            $this->writeTo($user, $event, $left);
        }
    }

    private function writeTo(User $user, AssignmentReminderRequested $event, string $left): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send(new AtharLetter(
                copyKey: 'emails.assignment_due_reminder',
                values: [
                    'assignment' => $event->assignmentTitle,
                    'countdown' => $left,
                ],
                ctaUrl: route('assignments.show', ['assignment' => $event->assignmentId]),
                meta: ['assignments.deadline' => $event->dueAtLabel],
            ));
        } catch (\Throwable $exception) {
            Log::warning('mail.assignment_reminder_failed', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
