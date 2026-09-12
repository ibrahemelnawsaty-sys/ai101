<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AttendanceLow;
use App\Mail\AtharLetter;
use App\Services\Mail\MailPreferences;
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
    public function __construct(private readonly MailPreferences $preferences) {}

    public function handle(AttendanceLow $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        // The recipient's own choice, read at send time — not when the event
        // fired: this is queued, and the preference may change in between.
        if (! $this->preferences->allows($event->user, 'attendance_low')) {
            return;
        }

        $values = [
            'current' => $event->currentRate,
            'required' => $event->requiredRate,
        ];

        // "Attending the coming sessions raises it" is false after the last
        // session, so that case has its own words (D-77). Two literal copy
        // keys, so LetterContractTest checks both.
        $letter = $event->sessionsRemain
            ? new AtharLetter(copyKey: 'emails.attendance_low', values: $values, ctaUrl: route('attendance.index'))
            : new AtharLetter(copyKey: 'emails.attendance_low_final', values: $values, ctaUrl: route('attendance.index'));

        try {
            Mail::to($address)->send($letter);
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
