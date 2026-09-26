<?php

declare(strict_types=1);

namespace App\Presenters\Tickets;

use App\Models\SupportTicketAttachment;
use App\Services\Storage\PrivateFileService;
use App\Support\ViewModel;

/**
 * A picture or a video on one line of a ticket (D-124): shown inside the
 * page through a signed link that lasts fifteen minutes, minted here — on a
 * page that already passed the policy — and checked again on arrival.
 *
 * @see D-124 · PRD §12.5 · D-80 · CONSTITUTION art. 22, art. 24
 */
final class TicketFile extends ViewModel
{
    public static function from(SupportTicketAttachment $attachment): self
    {
        $name = (string) $attachment->original_name;

        return new self([
            'id' => (string) $attachment->getKey(),
            'name' => $name,
            'url' => app(PrivateFileService::class)->temporaryUrl('files.supportAttachment', ['attachment' => $attachment->getKey()]),
            'isVideo' => $attachment->isVideo(),
            'mime' => (string) $attachment->mime_type,
            'openLabel' => (string) __('support.show.open_file', ['name' => $name]),
            'videoLabel' => (string) __('support.show.video_label', ['name' => $name]),
        ]);
    }
}
