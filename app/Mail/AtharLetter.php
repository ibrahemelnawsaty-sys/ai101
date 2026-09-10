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
 *
 * Encrypted on the queue for the same reason EmailTokenLink is: the payload
 * sits in `jobs` until the cron drains it and in `failed_jobs` for fourteen
 * days after a failure, carrying the recipient's address and, for the
 * certificate and card letters, a signed download URL (D-62).
 */
final class AtharLetter extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  string  $copyKey  a block in emails.php, e.g. 'emails.certificate_issued'
     * @param  array<string, string|int>  $values  placeholder values for that block
     * @param  array<string, string>  $meta  TRANSLATION KEY => value, shown as a detail strip
     *
     * `$meta` takes the KEY, not the resolved label. Callers used to resolve it
     * themselves — `meta: [(string) __('certificates.serial') => $serial]` — and
     * that put the one part of the letter with no guard in the hands of four
     * separate files. All three of the resolving call sites were wrong:
     *
     *   · `assignments.due` is a GROUP, so `(string)` on it raised an
     *     "Array to string conversion" warning, which Laravel throws as an
     *     ErrorException, which the listener's own catch swallowed — so NO
     *     participant ever received an assignment-published letter, in any
     *     cohort, and the log recorded only the exception class.
     *   · `certificates.serial` and `project.deadline` do not exist, so `__()`
     *     handed back the key and the reader saw the literal ASCII string
     *     "certificates.serial" beside their serial number.
     *
     * Resolving here means `line()`'s guard — which exists precisely so nobody
     * reads "emails.certificate_issued.subject" — finally covers the detail
     * strip too (D-62).
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
                'meta' => $this->metaRows(),
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

    /**
     * The detail strip, with every label resolved here and nowhere else.
     *
     * A row is dropped rather than printed broken. A label that resolves to a
     * group, to nothing, or to its own key is a copy bug — and a copy bug must
     * not become a line of ASCII in the middle of an Arabic letter. Dropping
     * one row still delivers the letter; the alternative used to be no letter
     * at all.
     *
     * @return array<string, string>
     */
    private function metaRows(): array
    {
        $rows = [];

        foreach ($this->meta as $key => $value) {
            $label = __($key);

            // `__()` returns the key when nothing is translated, and the whole
            // array when the key names a group. Neither is a label.
            if (! is_string($label) || $label === '' || $label === $key) {
                continue;
            }

            // No is_scalar() guard: $meta is declared array<string, string> and
            // every caller honours it, so the guard could never be false and
            // PHPStan says so at level 8.
            $text = trim($value);

            if ($text === '') {
                continue;
            }

            $rows[$label] = $text;
        }

        return $rows;
    }
}
