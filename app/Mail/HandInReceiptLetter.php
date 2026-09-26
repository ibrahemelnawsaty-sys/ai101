<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\ResolvesLetterCopy;
use App\Services\Mail\EmailPalette;
use App\Support\QrPng;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The receipt of a final-project hand-in (D-122): the receipt code set large,
 * a QR code that opens the receipt page, what was handed in, and the next step
 * — waiting for the evaluation.
 *
 * Its own Mailable because its shape is its own (AtharLetter's rule: a letter
 * that genuinely needs a different shape gets its own class, not an option):
 * a code block and an image no other letter carries. The copy is still DATA
 * read through the same guards (ResolvesLetterCopy), and the shell is still
 * mail/layout.blade.php.
 *
 * The QR is drawn at SEND time from the receipt URL and embedded in the
 * message itself: the queued payload carries the URL, not an image, and no
 * client has to fetch a remote picture it may block. It links to a page that
 * asks for a sign-in and the policy — scanning it discloses nothing (D-122).
 *
 * Queued and encrypted on the queue like AtharLetter: the payload carries the
 * recipient's address and a link to their hand-in (D-62).
 *
 * @see PRD §9.14.2, §9.16 · FR-NOTIF-15 · D-49, D-62, D-122
 */
final class HandInReceiptLetter extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use ResolvesLetterCopy;
    use SerializesModels;

    /** The QR's size on the page, in CSS pixels; the PNG is drawn larger. */
    public const QR_DISPLAY_SIZE = 160;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  array<string, string|int>  $values  placeholders of the copy block
     * @param  array<string, string>  $meta  TRANSLATION KEY => value, the detail strip
     * @param  list<string>  $items  the labels of what was handed in, in order
     */
    public function __construct(
        public readonly string $copyKey,
        public readonly array $values,
        public readonly string $receiptCode,
        public readonly string $receiptUrl,
        public readonly array $meta = [],
        public readonly array $items = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->line('subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.hand-in-receipt',
            with: [
                'palette' => app(EmailPalette::class)->all(),
                'subject' => $this->line('subject'),
                'preheader' => $this->line('preheader'),
                'heading' => $this->line('heading'),
                'bodyText' => $this->line('body'),
                'codeLabel' => $this->line('code_label'),
                'code' => $this->receiptCode,
                'qrPng' => QrPng::of($this->receiptUrl),
                'qrAlt' => $this->line('qr_alt'),
                'qrHint' => $this->optionalLine('qr_hint'),
                'qrSize' => self::QR_DISPLAY_SIZE,
                'itemsLabel' => $this->optionalLine('items_label'),
                'items' => $this->items,
                'ctaLabel' => $this->optionalLine('cta'),
                'ctaUrl' => $this->receiptUrl,
                'footNote' => $this->optionalLine('next_steps'),
                'meta' => $this->metaRows(),
                'why' => __('emails.common.why_receiving', [
                    'program' => (string) config('athar.program_name'),
                ]),
                'platformName' => (string) config('athar.platform_name'),
                'tagline' => (string) config('athar.tagline'),
                'contactEmail' => (string) config('athar.email'),
            ],
        );
    }
}
