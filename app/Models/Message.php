<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One message inside a thread. Deletion is soft: `deleted_at` hides the body from
 * the conversation without destroying the record.
 *
 * @see BR-22 · PRD §7.6, §9.13 · PROJECT-CONTRACT §4
 */
class Message extends Model
{
    /** @use HasFactory<\Database\Factories\MessageFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'messages';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'thread_id',
        'sender_id',
        'body',
        'attachments',
        'sent_at',
        'edited_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

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
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Messages written by one account.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        return $query->where('sender_id', $user instanceof User ? $user->getKey() : $user);
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
     * BR-22: only messages of threads the account belongs to.
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
