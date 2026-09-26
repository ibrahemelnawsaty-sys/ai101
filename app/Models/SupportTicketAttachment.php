<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupportTicketAttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture or a video on one line of a support ticket (D-124): the
 * descriptor PrivateFileService::store() returned, and nothing a request
 * wrote. Served through `files.supportAttachment` — a signed link, and the
 * policy asked again on arrival.
 *
 * @property string $id
 * @property string $support_ticket_entry_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum
 * @property string $kind
 *
 * @see D-124 · PRD §12.5 · CONSTITUTION art. 24
 */
class SupportTicketAttachment extends Model
{
    /** @use HasFactory<SupportTicketAttachmentFactory> */
    use HasFactory;

    use HasUuids;

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    protected $table = 'support_ticket_attachments';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SupportTicketEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(SupportTicketEntry::class, 'support_ticket_entry_id');
    }

    public function isVideo(): bool
    {
        return $this->getAttribute('kind') === self::KIND_VIDEO;
    }
}
