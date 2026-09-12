<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EmailTokenIssued;
use App\Mail\EmailTokenLink;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The listener that was missing.
 *
 * `app/Listeners` did not exist. Four events — `EmailTokenIssued`,
 * `AccountRegistered`, `AccountVerified`, `PasswordChanged` — were dispatched
 * into a room with nobody in it. This one closes the seam that
 * `IssuesEmailTokens` describes in its own comment: the controller mints the
 * token and announces it, and the mail layer listens and sends.
 *
 * Listeners are discovered by the type they accept, and `bootstrap/app.php`
 * now says so explicitly rather than trusting a framework default. The failure
 * mode of an unregistered listener is silence — it does not error, it simply
 * never runs — and silence is what hid this gap in the first place.
 *
 * FAILURE IS SWALLOWED, DELIBERATELY. A send that fails must not roll anything
 * back: the token is already stored and still valid, and the account is intact.
 * It is logged as a warning with the user id and never the address or the
 * token — a log line is diagnostics, not a record of who did what, and it must
 * carry no personal data (art. 12).
 *
 * @see BR-30 · PRD §9.2.3, §9.3.3 · D-02, D-49
 */
final class SendEmailTokenLink implements ShouldBeEncrypted, ShouldQueue
{
    /*
     * ENCRYPTED, because this listener's payload carries a LIVE credential.
     *
     * `EmailTokenIssued` holds `plainToken` and `url` — the single-use value
     * that resets a password — and a queued listener is stored whole in
     * `jobs.payload` until the per-minute cron reaches it, then in
     * `failed_jobs.payload` for fourteen days if delivery fails. The event's own
     * docblock promises the plaintext is never stored. It was, in a table that
     * a phpMyAdmin session, a leaked DB_PASSWORD or a restored backup all read.
     *
     * `SerializesModels` does not help here: the token is a string, not a model.
     * `Dispatcher::createListenerAndJob` sets `$job->shouldBeEncrypted` from
     * this interface, and `Queue::jobShouldBeEncrypted` honours it — verified in
     * vendor, not assumed (D-65).
     */
    public function handle(EmailTokenIssued $event): void
    {
        $address = (string) $event->user->getAttribute('email');

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(
                new EmailTokenLink($event->user, $event->type, $event->url),
            );
        } catch (\Throwable $exception) {
            Log::warning('mail.token_link_failed', [
                'user_id' => $event->user->getKey(),
                'type' => $event->type->value,
                // The exception class, not its message: a driver message can
                // repeat the address it failed to reach.
                'exception' => $exception::class,
            ]);
        }
    }
}
