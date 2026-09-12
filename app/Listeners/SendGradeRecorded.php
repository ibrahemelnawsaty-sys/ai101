<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\GradeRecorded;
use App\Mail\AtharLetter;
use App\Services\Mail\MailPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a participant a score has been recorded.
 *
 * The letter states the number and sends the reader to the platform for the
 * trainer's note: feedback belongs where it can be replied to.
 *
 * @see BR-11 · PRD §9.15, §9.16.1 · D-51
 */
final class SendGradeRecorded implements ShouldQueue
{
    public function __construct(private readonly MailPreferences $preferences) {}

    public function handle(GradeRecorded $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        // The recipient's own choice, read at send time — not when the event
        // fired: this is queued, and the preference may change in between.
        if (! $this->preferences->allows($event->user, 'grade_recorded')) {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.grade_recorded',
                values: [
                    'item' => $event->itemName,
                    'score' => $event->score,
                    'max' => $event->max,
                ],
                ctaUrl: route('grades'),
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the fact it was
            // announcing. Logged without the address or any personal data
            // (art. 12), and never rethrown.
            Log::warning('mail.grade_recorded_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
