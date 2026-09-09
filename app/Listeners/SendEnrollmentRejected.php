<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EnrollmentRejected;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells somebody their application was not accepted, and why.
 *
 * There is no button. A refusal that ends in a call to action reads as a sales
 * pitch attached to bad news; the copy offers the next cohort in words and
 * leaves the reader alone.
 *
 * @see PRD §9.16.1 · CONSTITUTION.md Article 7 · D-51
 */
final class SendEnrollmentRejected implements ShouldQueue
{
    public function handle(EnrollmentRejected $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.enrollment_rejected',
                values: [
                    'program' => $event->programName,
                    'reason' => $event->reason,
                    'email' => (string) config('athar.email'),
                ],
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the fact it was
            // announcing. Logged without the address or any personal data
            // (art. 12), and never rethrown.
            Log::warning('mail.enrollment_rejected_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
