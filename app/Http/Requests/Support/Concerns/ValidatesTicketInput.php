<?php

declare(strict_types=1);

namespace App\Http\Requests\Support\Concerns;

use App\Models\SupportTicket;
use App\Models\User;
use App\Rules\SniffedFileType;
use App\Services\Audit\AuditLogger;
use App\Services\Tickets\TicketAttachments;
use App\Services\Tickets\TicketWorkflow;
use App\Services\Time\Clock;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

/**
 * The rules every support ticket form shares (D-124): the text, the optional
 * link, and up to three pictures or videos of ten megabytes each. The type is
 * the one the BYTES declare (SniffedFileType), and PrivateFileService sniffs
 * it again before anything reaches the disk (art. 24). The numbers come from
 * config/athar.php through TicketAttachments (BR-36).
 *
 * @see D-124 · CONSTITUTION art. 5, art. 24
 */
trait ValidatesTicketInput
{
    public static function bodyMax(): int
    {
        $configured = config('athar.support.body_max');

        return is_int($configured) && $configured > 0 ? $configured : 5000;
    }

    /**
     * @return array<string, mixed>
     */
    protected function attachmentRules(): array
    {
        return [
            'link' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'attachments' => ['nullable', 'array', 'max:'.TicketAttachments::maxFiles()],
            'attachments.*' => [
                'file',
                'max:'.TicketAttachments::maxKilobytes(),
                new SniffedFileType(array_keys(TicketAttachments::TYPES), (string) __('support.errors.file_type')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attachmentMessages(): array
    {
        $files = TicketAttachments::maxFiles();

        return [
            'link.url' => (string) __('support.errors.link'),
            'link.max' => (string) __('support.errors.link'),
            'attachments.max' => (string) __('support.errors.files_count', [
                'count' => trans_choice('support.count.files', $files, ['count' => $files]),
            ]),
            'attachments.*.file' => (string) __('support.errors.file_type'),
            'attachments.*.max' => (string) __('support.errors.file_size', ['size' => TicketAttachments::maxMegabytes()]),
        ];
    }

    /**
     * The files as they arrived, in order.
     *
     * @return list<UploadedFile>
     */
    public function uploads(): array
    {
        $files = $this->file('attachments');

        return is_array($files) ? array_values($files) : [];
    }

    public function link(): ?string
    {
        $link = $this->validated('link');

        return is_string($link) && trim($link) !== '' ? trim($link) : null;
    }

    public function ticket(): SupportTicket
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->route('ticket');

        return $ticket;
    }

    /**
     * A form the ticket's page offered, sent after the ticket moved on —
     * closed by its participant or by the clock, sent up a level, handed to
     * another coordinator — is refused like any other, and the refusal is
     * written to the audit trail with its IP (art. 8). But its sender is
     * told what happened, on the ticket's page and with what they wrote
     * kept (art. 15, art. 17), not shown a page that says the ticket
     * belongs to another role. The page's forms carry the state they were
     * drawn in (`seen`); a request without it, or from someone who cannot
     * read the ticket at all, answers 403 as before (art. 22).
     */
    protected function failedAuthorization(): void
    {
        $ticket = $this->route('ticket');
        $user = $this->user();
        $seen = $this->input('seen');
        $now = Clock::now();

        if ($ticket instanceof SupportTicket
            && $user instanceof User
            && is_string($seen) && $seen !== ''
            && ! hash_equals(TicketWorkflow::formStamp($ticket, $now), $seen)
            && $user->can('view', $ticket)) {
            app(AuditLogger::class)->deniedRequest($this, 'support.stale_form');

            throw new HttpResponseException(redirect()
                ->route('support.show', $ticket)
                ->withInput($this->except(['_token', 'seen']))
                ->withErrors(['message' => __(TicketWorkflow::isClosedAt($ticket, $now) ? 'support.errors.closed' : 'support.errors.moved_on')]));
        }

        parent::failedAuthorization();
    }

    protected function userMay(string $ability): bool
    {
        $ticket = $this->route('ticket');
        $user = $this->user();

        return $ticket instanceof SupportTicket && $user !== null && $user->can($ability, $ticket);
    }
}
