<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\EmailTokenType;
use App\Models\User;
use App\Services\Mail\EmailPalette;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The activation link and the recovery link — the two letters that carry a
 * single-use token.
 *
 * WHY THIS CLASS DID NOT EXIST UNTIL NOW
 * `IssuesEmailTokens` has always ended with `EmailTokenIssued::dispatch(...)`,
 * commented "so the mail layer can send it". Four events were being dispatched
 * and `app/Listeners` did not exist: every one of them went nowhere. Meanwhile
 * `PasswordResetController::email()` answered the visitor with
 * `__('passwords.sent')` — "we sent you a link" — and sent nothing. The copy for
 * twenty letters sat finished in `lang/<locale>/emails.php` with no sender.
 *
 * QUEUED, ALWAYS. Shared hosting caps a web request and an SMTP handshake to a
 * host we do not control can take seconds. The token is already saved when this
 * is queued, so a slow or failed send never costs the user their token — it
 * costs them a letter, which is a delivery failure and not a broken account
 * (the wording is `EmailTokenIssued`'s own).
 *
 * @see BR-29, BR-30, BR-36 · PRD §9.2.3, §9.3.3, §9.16 · D-02, D-49
 */
final class EmailTokenLink extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** Retries spread over the cron-driven queue rather than hammering the host. */
    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly User $user,
        public readonly EmailTokenType $type,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __($this->key().'.subject', [
                'program' => (string) config('athar.program_name'),
            ]),
        );
    }

    public function content(): Content
    {
        $palette = app(EmailPalette::class)->all();

        return new Content(
            view: 'mail.token-link',
            with: [
                'palette' => $palette,
                'key' => $this->key(),
                'url' => $this->url,
                'name' => $this->displayName(),
                'programName' => (string) config('athar.program_name'),
                'platformName' => (string) config('athar.platform_name'),
                'contactEmail' => (string) config('athar.email'),
                'homeUrl' => (string) config('app.url'),
            ],
        );
    }

    /** The `lang/<locale>/emails.php` block this letter reads its copy from. */
    private function key(): string
    {
        return $this->type === EmailTokenType::Reset
            ? 'emails.password_reset'
            : 'emails.verify';
    }

    /**
     * The greeting falls back to a neutral one rather than printing an empty
     * name: a letter that opens with a greeting, a blank and a comma reads worse
     * than one that never used a name. Both wordings live in `emails.common`.
     */
    private function displayName(): ?string
    {
        $profile = $this->user->getRelationValue('profile');

        if ($profile === null) {
            return null;
        }

        $first = (string) $profile->getAttribute('first_name_ar');

        return $first === '' ? null : $first;
    }
}
