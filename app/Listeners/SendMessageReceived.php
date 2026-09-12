<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MessageReceived;
use App\Mail\AtharLetter;
use App\Models\ThreadParticipant;
use App\Services\Mail\MailPreferences;
use App\Services\Messages\Presence;
use App\Services\Time\Clock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells somebody a message is waiting for them in the platform.
 *
 * An excerpt, never the whole message: reproducing a conversation in e-mail
 * defeats the point of having it inside the platform, where it is scoped,
 * reportable and auditable.
 *
 * PRD §9.16.1 says "platform (and e-mail if offline)". It wrote to everyone,
 * for every message: sixty letters for one line in the cohort group, and one
 * more each time anybody replied (D-83). Now, read when the queue runs:
 *   · a member who muted the conversation gets nothing (FR-MSG-12);
 *   · a member who switched the letter off gets nothing (D-66);
 *   · a member using the platform right now reads it there (Presence);
 *   · everyone else gets ONE letter per conversation per window, however many
 *     messages arrive in it — claimed atomically on `last_emailed_at` (AMB-14).
 *
 * @see PRD §9.13.2, §9.16.1 · FR-NOTIF-22, FR-MSG-12 · D-51, D-83
 */
final class SendMessageReceived implements ShouldQueue
{
    public function __construct(
        private readonly MailPreferences $preferences,
        private readonly Presence $presence,
    ) {}

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

        $now = Clock::now();
        $recipientId = (string) $event->recipient->getKey();

        // A job queued before D-83 carries no thread: unserialising it leaves
        // the property uninitialised, and reading it would throw. It is sent
        // as it was then. Asked of reflection, because the type says string
        // and only the queue payload knows otherwise.
        $threadId = (new \ReflectionProperty($event, 'threadId'))->isInitialized($event) ? $event->threadId : '';

        if ($threadId === '') {
            $this->deliver($address, $event, route('messages.index'));

            return;
        }

        if ($this->presence->isOnline($recipientId, $now)) {
            return;
        }

        $window = max(0, (int) config('athar.messages.email_every_minutes'));

        // The claim: a member of this thread, not muted, not e-mailed about it
        // inside the window. toBase(): a plain UPDATE stamped with Clock, not
        // the framework clock (BR-07).
        $claimed = ThreadParticipant::query()
            ->where('thread_id', $threadId)
            ->where('user_id', $recipientId)
            ->where('is_muted', false)
            ->where(static function (Builder $query) use ($now, $window): void {
                $query->whereNull('last_emailed_at')
                    ->orWhere('last_emailed_at', '<=', $now->subMinutes($window));
            })
            ->toBase()
            ->update(['last_emailed_at' => $now]);

        if ($claimed === 0) {
            return;
        }

        $this->deliver($address, $event, route('messages.index', ['thread' => $threadId]));
    }

    private function deliver(string $address, MessageReceived $event, string $link): void
    {
        try {
            Mail::to($address)->send(new AtharLetter(
                copyKey: 'emails.message_received',
                values: [
                    'name' => $event->senderName,
                    'thread' => $event->threadTitle,
                    'excerpt' => $event->excerpt,
                ],
                ctaUrl: $link,
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
