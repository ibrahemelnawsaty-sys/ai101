<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ThreadType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An internal conversation: a direct thread with a trainer, a cohort group, or an
 * announcement channel.
 *
 * Visibility is deliberately stricter than cohort scope: a thread is reachable only
 * by the accounts listed in `thread_participants`, so a trainer never reads a
 * conversation they are not part of (BR-22).
 *
 * @see BR-22, BR-23 · PRD §7.6, §9.13 · PROJECT-CONTRACT §4
 */
class Thread extends Model
{
    /** @use HasFactory<\Database\Factories\ThreadFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'threads';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'type',
        'title',
        'created_by',
        'is_locked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ThreadType::class,
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'is_locked' => false,
    ];

    // --------------------------------------------------------- relationships

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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ThreadParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'thread_participants')
            ->withPivot(['last_read_at', 'is_muted'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('sent_at');
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, ThreadType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Threads the account is actually a participant of.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereIn(
            'id',
            ThreadParticipant::query()->where('user_id', $userId)->select('thread_id'),
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        return $query->where('cohort_id', $cohort instanceof Cohort ? $cohort->getKey() : $cohort);
    }

    /**
     * BR-22: membership, not role, decides who reads a conversation. Administrators
     * reach every thread only through the audited preview mode.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn(
            'id',
            ThreadParticipant::query()->where('user_id', $user->getKey())->select('thread_id'),
        );
    }
}
