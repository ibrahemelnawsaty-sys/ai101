<?php

declare(strict_types=1);

namespace App\Http\Requests\Support\Concerns;

use App\Models\SupportTicket;
use App\Rules\SniffedFileType;
use App\Services\Tickets\TicketAttachments;
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

    protected function userMay(string $ability): bool
    {
        $ticket = $this->route('ticket');
        $user = $this->user();

        return $ticket instanceof SupportTicket && $user !== null && $user->can($ability, $ticket);
    }
}
