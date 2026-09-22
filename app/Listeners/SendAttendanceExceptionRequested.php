<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AttendanceExceptionRequested;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Confirms a request was received — separate from the decision, which is
 * the one that actually settles anything for the participant.
 *
 * @see D-106
 */
final class SendAttendanceExceptionRequested implements ShouldQueue
{
    public function handle(AttendanceExceptionRequested $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.attendance_exception_requested',
                values: [
                    'session' => $event->sessionTitle,
                    'type' => $event->type->label(),
                ],
                ctaUrl: route('attendance.index'),
            ));
        } catch (\Throwable $exception) {
            Log::warning('mail.attendance_exception_requested_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
