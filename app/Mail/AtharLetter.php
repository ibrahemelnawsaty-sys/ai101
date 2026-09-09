<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Mail\EmailPalette;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One Mailable for every letter in the notification matrix.
 *
 * WHY ONE CLASS AND NOT TWENTY
 * `lang/<locale>/emails.php` already describes twenty letters, and every one of
 * them has the same shape: a subject, a preheader, a heading, a paragraph, and
 * at most one button. Twenty Mailables would be twenty copies of that shape,
 * differing only in which translation key they read — and the twenty-first
 * would be written by copying the twentieth, inheriting whatever had drifted.
 *
 * So the letter is DATA: a copy key, the values its placeholders need, an
 * optional call to action, and an optional detail strip. The shell in
 * `mail/layout.blade.php` renders all of them.
 *
 * A letter that genuinely needs a different shape gets its own Mailable rather
 * than an option on this one. `EmailTokenLink` stays separate for exactly that
 * reason: the security wording, the expiry note and the raw-link fallback are
 * not decoration a normal letter would want.
 *
 * QUEUED, ALWAYS. An SMTP handshake to a host we do not control can take
 * seconds, and shared hosting caps a web request.
 *
 * @see PRD §9.16, §9.16.1 · BR-36 · D-49
 */
final class AtharLetter extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  string  $copyKey  a block in emails.php, e.g. 'emails.certificate_issued'
     * @param  array<string, string|int>  $values  placeholder values for that block
     * @param  array<string, string>  $meta  label => value, shown as a detail strip
     */
    public function __construct(
        public readonly string $copyKey,
        public readonly array $values = [],
        public readonly ?string $ctaUrl = null,
        public readonly array $meta = [],
        public readonly ?string $eyebrow = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->line('subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.letter',
            with: [
                'palette' => app(EmailPalette::class)->all(),
                'subject' => $this->line('subject'),
                'preheader' => $this->line('preheader'),
                'eyebrow' => $this->eyebrow,
                'heading' => $this->line('heading'),
                'lede' => null,
                'bodyText' => $this->line('body'),
                'ctaLabel' => $this->optionalLine('cta'),
                'ctaUrl' => $this->ctaUrl,
                'footNote' => $this->optionalLine('expiry_note') ?? $this->optionalLine('next_steps'),
                'meta' => $this->meta,
                'why' => $this->optionalLine('not_you') ?? __('emails.common.why_receiving', [
                    'program' => (string) config('athar.program_name'),
                ]),
                'platformName' => (string) config('athar.platform_name'),
                'tagline' => (string) config('athar.tagline'),
                'contactEmail' => (string) config('athar.email'),
            ],
        );
    }

    /** A required line: its absence is a copy bug, and an empty string shows it. */
    private function line(string $name): string
    {
        $key = $this->copyKey.'.'.$name;
        $text = __($key, $this->values);

        // `__()` hands back the key itself when nothing is translated. A visitor
        // must never read "emails.certificate_issued.subject".
        return is_string($text) && $text !== $key ? $text : '';
    }

    /** An optional line: many letters have no button and no note. */
    private function optionalLine(string $name): ?string
    {
        $text = $this->line($name);

        return $text === '' ? null : $text;
    }
}
