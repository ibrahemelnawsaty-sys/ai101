<?php

declare(strict_types=1);

/**
 * A picture on one line of a support ticket (D-124). The row only: a test that
 * needs the bytes stores them through PrivateFileService.
 *
 * @see D-124 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportTicketAttachment>
 */
final class SupportTicketAttachmentFactory extends Factory
{
    /** @var class-string<SupportTicketAttachment> */
    protected $model = SupportTicketAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_entry_id' => SupportTicketEntry::factory(),
            'disk' => 'private',
            'path' => 'support/'.Str::uuid()->toString().'.png',
            'original_name' => 'screenshot.png',
            'mime_type' => 'image/png',
            'size_bytes' => 1024,
            'checksum' => str_repeat('a', 64),
            'kind' => SupportTicketAttachment::KIND_IMAGE,
        ];
    }
}
