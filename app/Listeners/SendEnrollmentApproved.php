<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EnrollmentApproved;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells somebody their place is confirmed.
 *
 * The one letter a person is actively waiting for, and the one whose absence is
 * felt as silence rather than as a missing feature.
 *
 * @see PRD §9.16.1 · D-51
 */
final class SendEnrollmentApproved implements ShouldQueue
{
    public function handle(EnrollmentApproved $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.enrollment_approved',
                values: [
                    'cohort' => $event->cohortName,
                ],
                ctaUrl: route('dashboard'),
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the fact it was
            // announcing. Logged without the address or any personal data
            // (art. 12), and never rethrown.
            Log::warning('mail.enrollment_approved_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
