<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PasswordChanged;
use App\Mail\AtharLetter;
use App\Support\Dates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The security notice that follows a password change.
 *
 * This is the letter that matters most of the twenty, and it was the one least
 * likely to be missed as absent: nobody notices a warning that never arrives.
 * If an account is taken over and its password changed, this letter is the only
 * thing that tells the owner — and `PasswordChanged` was being dispatched into
 * an empty room like the other three.
 *
 * It carries no preference link and is never suppressible: `emails.common`
 * calls it a security letter that always arrives, and that is the correct
 * behaviour, not an oversight.
 *
 * @see PRD §9.3.3, §9.16 · CONSTITUTION.md Article 24 · D-49
 */
final class SendPasswordChangedNotice implements ShouldQueue
{
    public function handle(PasswordChanged $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.password_changed',
                values: [
                    // The event's own instant, not "now": the letter is queued
                    // and may be sent minutes later, and a security notice that
                    // reports the wrong time is worse than none. Formatted the
                    // way every screen formats it (art. 11, art. 15).
                    'datetime' => Dates::dateTime($event->changedAt),
                    'email' => (string) config('athar.email'),
                ],
            ));
        } catch (\Throwable $exception) {
            Log::warning('mail.password_changed_failed', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
