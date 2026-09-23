<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BroadcastKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One send from the administrator's send screen: a message, or a manual
 * reminder of sessions or of unsubmitted work (D-87).
 *
 * No framework timestamps: `created_at` is written from Clock by the one
 * service that creates rows, so the history and the cooldown read the server's
 * own time (BR-07).
 *
 * @property string $id
 * @property string $cohort_id
 * @property BroadcastKind $kind
 * @property string|null $subject
 * @property string|null $body
 * @property bool $in_app
 * @property string|null $sent_by
 * @property int $recipients
 * @property int $emails
 *
 * @see PRD §9.16, §9.18 · D-87
 */
final class Broadcast extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'kind',
        'subject',
        'body',
        'in_app',
        'sent_by',
        'recipients',
        'emails',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BroadcastKind::class,
            'in_app' => 'boolean',
            'recipients' => 'integer',
            'emails' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cohort, $this>
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfKind(Builder $query, BroadcastKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }
}
