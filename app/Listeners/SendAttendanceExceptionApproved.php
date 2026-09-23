<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AttendanceExceptionApproved;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * @see D-106
 */
final class SendAttendanceExceptionApproved implements ShouldQueue
{
    public function handle(AttendanceExceptionApproved $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.attendance_exception_approved',
                values: [
                    'session' => $event->sessionTitle,
                    'type' => $event->type->label(),
                ],
                ctaUrl: route('attendance.index'),
            ));
        } catch (\Throwable $exception) {
            Log::warning('mail.attendance_exception_approved_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
