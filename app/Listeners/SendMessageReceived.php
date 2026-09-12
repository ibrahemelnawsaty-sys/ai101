<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MessageReceived;
use App\Mail\AtharLetter;
use App\Services\Mail\MailPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells somebody a message is waiting for them in the platform.
 *
 * An excerpt, never the whole message: reproducing a conversation in e-mail
 * defeats the point of having it inside the platform, where it is scoped,
 * reportable and auditable.
 *
 * @see PRD §9.14, §9.16.1 · D-51
 */
final class SendMessageReceived implements ShouldQueue
{
    public function __construct(private readonly MailPreferences $preferences) {}

    public function handle(MessageReceived $event): void
    {
        $address = (string) $event->recipient->getAttribute('email');

        if ($address === '') {
            return;
        }

        // The recipient's own choice, read at send time — not when the event
        // fired: this is queued, and the preference may change in between.
        if (! $this->preferences->allows($event->recipient, 'message_received')) {
            return;
        }

        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.message_received',
                values: [
                    'name' => $event->senderName,
                    'thread' => $event->threadTitle,
                    'excerpt' => $event->excerpt,
                ],
                ctaUrl: route('messages.index'),
            ));
        } catch (\Throwable $exception) {
            // A letter that fails to leave changes nothing about the fact it was
            // announcing. Logged without the address or any personal data
            // (art. 12), and never rethrown.
            Log::warning('mail.message_received_failed', [
                'user_id' => $event->recipient->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
