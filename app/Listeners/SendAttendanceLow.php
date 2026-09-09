<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AttendanceLow;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Warns a participant their attendance has fallen below the certificate
 * threshold — while there is still time to recover it.
 *
 * Both numbers are stated. "You are below the requirement" without saying by
 * how much leaves the reader unable to judge whether to worry.
 *
 * @see BR-26 · PRD §9.9, §9.16.1 · D-51
 */
final class SendAttendanceLow implements ShouldQueue
{
    public function handle(AttendanceLow $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.attendance_low',
                values: [
                    'current' => $event->currentRate,
                    'required' => $event->requiredRate,
                ],
                ctaUrl: route('attendance.index'),
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the fact it was
            // announcing. Logged without the address or any personal data
            // (art. 12), and never rethrown.
            Log::warning('mail.attendance_low_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
