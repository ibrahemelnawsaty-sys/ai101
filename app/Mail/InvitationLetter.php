<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Mail\EmailPalette;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The letter that carries a trainee's first credentials.
 *
 * WHY IT IS NOT `AtharLetter`
 * `AtharLetter` renders a heading, a paragraph, at most one button and an
 * optional detail strip. A password is none of those. It has to be presented
 * so it can be READ CHARACTER BY CHARACTER and selected on a phone: its own
 * block, its own rhythm, left-to-right inside a right-to-left letter, and never
 * broken across a line. Squeezing it into a detail-strip row would make the
 * single most important string in the message look like a footnote.
 *
 * `EmailTokenLink` is separate for the same reason — its security wording and
 * raw-link fallback are not decoration a normal letter would want.
 *
 * WHY IT IS ENCRYPTED ON THE QUEUE, AND WHY THERE IS NO EVENT
 * The plaintext password is a constructor argument, and a queued Mailable's
 * arguments are serialised into `jobs.payload` until the per-minute cron drains
 * them. `ShouldBeEncrypted` makes the framework encrypt that payload with
 * APP_KEY.
 *
 * And unlike every other letter in this platform, this one is NOT dispatched
 * through an event. An event plus a queued listener would put the plaintext
 * through TWO queue rows instead of one — the listener's payload and then the
 * mailable's. One copy of a live credential is the most that should exist, so
 * the inviter sends this directly. That is a deliberate deviation from the
 * mail-layer pattern and is recorded as such (D-63).
 *
 * NOTHING HERE IS EVER LOGGED. The plaintext exists for one request, reaches
 * one encrypted queue row, and is gone. It is not audited, not flashed, and
 * never shown back to the administrator who created the account: an
 * administrator who can read a trainee's password can sign in as that trainee
 * without leaving the impersonation record BR-34 requires.
 *
 * @see PRD §9.2, §9.16 · BR-30, BR-34 · CONSTITUTION Art. 12 · D-63
 */
final class InvitationLetter extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * Primitives only — no models. A queued job that carries an Eloquent model
     * re-fetches it when it runs, and throws if the row has moved on; the cron
     * may drain this minutes after the account was made.
     *
     * @param  string  $displayName  the trainee's own name, for the greeting
     * @param  string  $email  the sign-in identifier, shown so it can be copied
     * @param  string  $password  PLAINTEXT, temporary, never stored anywhere else
     * @param  string  $expiresOn  already formatted for display, in Riyadh time
     */
    public function __construct(
        public readonly string $displayName,
        public readonly string $email,
        public readonly string $password,
        public readonly string $loginUrl,
        public readonly string $expiresOn,
        public readonly string $programName,
        public readonly string $cohortName,
    ) {}

    /**
     * The subject names the programme and nothing else.
     *
     * It must not contain the word "password", the address, or any part of the
     * credential: a subject line is shown on a lock screen, read aloud by
     * notification assistants, and kept in mail-server logs that the body is
     * not.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('emails.invitation.subject', [
            'program' => $this->programName,
        ]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invitation',
            text: 'mail.invitation-text',
            with: [
                'palette' => app(EmailPalette::class)->all(),
                'subject' => (string) __('emails.invitation.subject', ['program' => $this->programName]),
                'preheader' => (string) __('emails.invitation.preheader', ['program' => $this->programName]),
                'eyebrow' => (string) config('athar.program_short_name'),
                'heading' => (string) __('emails.invitation.heading'),
                'greeting' => (string) __('emails.common.greeting', ['name' => $this->displayName]),
                'bodyText' => (string) __('emails.invitation.body', [
                    'program' => $this->programName,
                    'cohort' => $this->cohortName,
                ]),
                'credentialsTitle' => (string) __('emails.invitation.credentials_title'),
                'emailLabel' => (string) __('emails.invitation.email_label'),
                'passwordLabel' => (string) __('emails.invitation.password_label'),
                'email' => $this->email,
                'password' => $this->password,
                'temporaryNote' => (string) __('emails.invitation.temporary_note', ['date' => $this->expiresOn]),
                'ctaLabel' => (string) __('emails.invitation.cta'),
                'ctaUrl' => $this->loginUrl,
                'footNote' => (string) __('emails.invitation.next_steps'),
                'notYou' => (string) __('emails.invitation.not_you', [
                    'email' => (string) config('athar.email'),
                ]),
                'platformName' => (string) config('athar.platform_name'),
                'tagline' => (string) config('athar.tagline'),
                'contactEmail' => (string) config('athar.email'),
                'programName' => $this->programName,
                'cohortName' => $this->cohortName,
            ],
        );
    }
}
