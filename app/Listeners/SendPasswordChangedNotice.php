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
    /**
     * The one source this letter is NOT sent for is the forced first change.
     *
     * `$event->source` had three writers and no reader, so an invited trainee
     * who replaced their temporary password — because the platform stopped them
     * and made them — received a security warning about it moments later. The
     * queue is drained by a per-minute cron, so it arrived after the invitation
     * and out of order with it: sixty invitations would have meant a hundred
     * and twenty letters through one shared mailbox on day one, half of them
     * telling people something they had just been ordered to do (D-63).
     *
     * A change made anywhere else still notifies. That is the whole point of
     * the letter: the owner learns about a change they did not make.
     */
    private const SUPPRESSED_SOURCES = ['invitation'];

    public function handle(PasswordChanged $event): void
    {
        if (in_array($event->source, self::SUPPRESSED_SOURCES, true)) {
            return;
        }

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
