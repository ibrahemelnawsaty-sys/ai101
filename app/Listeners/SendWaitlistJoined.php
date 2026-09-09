<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WaitlistJoined;
use App\Mail\AtharLetter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The confirmation for a visitor who left an address when registration was shut.
 *
 * WHY THIS EXISTS
 * `WaitlistJoined` was dispatched into an empty room: the event, the copy at
 * `emails.waitlist_confirmation` and the audit record all existed, and no
 * listener did. The landing page promised to write as soon as the next cohort
 * opens, and nothing anywhere could send that letter. A promise the platform
 * cannot keep is the shape art. 7 rules out, so the promise gets its sender.
 *
 * This letter goes to somebody with NO account: no name to greet, no cohort to
 * name, and no call to action — there is nothing yet to click. The address is
 * all the platform knows, which is exactly what the copy claims.
 *
 * @see BR-30 · PRD §9.1.2, §9.16.1 · CONSTITUTION.md Article 7 · D-51
 */
final class SendWaitlistJoined implements ShouldQueue
{
    public function handle(WaitlistJoined $event): void
    {
        $address = trim($event->email);

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.waitlist_confirmation',
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave does not undo the interest: the
            // audit record is already written and the centre can still read it.
            // Logged without the address (art. 12) and never rethrown, so a mail
            // outage cannot turn into a 500 on a public page.
            Log::warning('mail.waitlist_confirmation_failed', [
                'exception' => $exception::class,
            ]);
        }
    }
}
