<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportTicketEntryType;
use App\Enums\SupportTicketLevel;
use Database\Factories\SupportTicketEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a support ticket's timeline (D-124). Written by TicketWorkflow
 * alone, stamped by Clock (BR-07), never edited afterwards: the timeline is
 * what happened.
 *
 * @property string $id
 * @property string $support_ticket_id
 * @property int $position
 * @property string|null $actor_id
 * @property SupportTicketEntryType $type
 * @property string|null $body
 * @property bool $is_internal
 * @property SupportTicketLevel|null $from_level
 * @property SupportTicketLevel|null $to_level
 * @property string|null $target_id
 * @property string|null $link_url
 *
 * @see D-124
 */
class SupportTicketEntry extends Model
{
    /** @use HasFactory<SupportTicketEntryFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'support_ticket_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, bool> */
    protected $attributes = [
        'is_internal' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'type' => SupportTicketEntryType::class,
            'is_internal' => 'boolean',
            'from_level' => SupportTicketLevel::class,
            'to_level' => SupportTicketLevel::class,
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }

    /**
     * @return HasMany<SupportTicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * The lines the participant reads: everything not kept internal.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShownToParticipant(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }
}
