<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\GradeRevised;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a participant a score they had already been given has changed.
 *
 * Separate from the first-time letter because the reader needs a different
 * sentence: not "here is your grade" but "your grade moved, and here is why".
 * A silent revision is the kind of thing that ends trust in a whole platform.
 *
 * @see BR-11 · PRD §9.15, §9.16.1 · D-51
 */
final class SendGradeRevised implements ShouldQueue
{
    public function handle(GradeRevised $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.grade_revised',
                values: [
                    'item' => $event->itemName,
                    'score' => $event->score,
                    'max' => $event->max,
                    'reason' => $event->reason,
                ],
                ctaUrl: route('grades'),
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the fact it was
            // announcing. Logged without the address or any personal data
            // (art. 12), and never rethrown.
            Log::warning('mail.grade_revised_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
