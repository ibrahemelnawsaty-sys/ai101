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
 * A letter whose words were decided before it was queued: an administrator's
 * own message to a cohort, or a digest of a trainee's sessions or unfinished
 * work (D-87).
 *
 * WHY NOT `AtharLetter`
 * AtharLetter reads every line from a copy block and prints ONE escaped
 * paragraph. An administrator's message has paragraphs, and a digest has a
 * list whose length is only known at send time — neither is a copy block.
 * This letter takes its lines already written, in the same shell, and still
 * escapes every one of them: nothing here is ever printed as HTML.
 *
 * Queued and encrypted like every letter: the payload names the recipient
 * and, for a message, carries its whole text until the cron drains it (D-62).
 *
 * @see PRD §9.16, §9.18 · D-62, D-87
 */
final class NoticeLetter extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<string>  $paragraphs  plain text, one entry per paragraph
     * @param  list<array{0: string, 1: string}>  $rows  [label, value] pairs, in order
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly array $paragraphs,
        public readonly array $rows = [],
        public readonly ?string $ctaLabel = null,
        public readonly ?string $ctaUrl = null,
        public readonly ?string $eyebrow = null,
        public readonly ?string $preheader = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notice',
            with: [
                'palette' => app(EmailPalette::class)->all(),
                'subject' => $this->subjectLine,
                'preheader' => $this->preheader ?? ($this->paragraphs[0] ?? ''),
                'eyebrow' => $this->eyebrow,
                'heading' => $this->heading,
                'paragraphs' => $this->paragraphs,
                'rows' => $this->rows,
                'ctaLabel' => $this->ctaLabel,
                'ctaUrl' => $this->ctaUrl,
                'why' => __('emails.common.why_receiving', [
                    'program' => (string) config('athar.program_name'),
                ]),
                'platformName' => (string) config('athar.platform_name'),
                'tagline' => (string) config('athar.tagline'),
                'contactEmail' => (string) config('athar.email'),
            ],
        );
    }

    /**
     * An administrator's text as paragraphs: split on blank lines, each line
     * trimmed, the empty ones dropped. Line breaks inside a paragraph are kept
     * and printed as breaks by the template — still escaped.
     *
     * @return list<string>
     */
    public static function paragraphsOf(string $text): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split('/\n\s*\n/u', $normalised) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $block): string => trim($block), $blocks),
            static fn (string $block): bool => $block !== '',
        ));
    }
}
