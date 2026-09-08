<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of one account in one thread, carrying the read marker.
 *
 * `last_read_at` must not move while an administrator previews an account
 * (BR-34); that guard lives in the preview middleware and message service.
 *
 * @see BR-22, BR-34 · PRD §7.6, §9.13 · PROJECT-CONTRACT §4
 */
class ThreadParticipant extends Model
{
    /** @use HasFactory<\Database\Factories\ThreadParticipantFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'thread_participants';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'thread_id',
        'user_id',
        'last_read_at',
        'is_muted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'is_muted' => 'boolean',
        ];
    }

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'is_muted' => false,
    ];

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        $cohortId = $cohort instanceof Cohort ? $cohort->getKey() : $cohort;

        return $query->whereIn(
            'thread_id',
            Thread::query()->where('cohort_id', $cohortId)->select('id'),
        );
    }

    /**
     * BR-22: an account sees the membership rows of its own threads only.
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
            'thread_id',
            ThreadParticipant::query()->where('user_id', $user->getKey())->select('thread_id'),
        );
    }
}
