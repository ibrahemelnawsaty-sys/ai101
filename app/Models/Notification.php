<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-app notification. This is the platform's own table, not Laravel's
 * `notifications` morph table, so the User model does not use the Notifiable trait.
 *
 * Read state must not change while an administrator previews an account (BR-34).
 *
 * @see BR-22, BR-34 · PRD §7.6, §9.16 · PROJECT-CONTRACT §4
 */
class Notification extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'notifications';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'link',
        'is_read',
        'read_at',
        'channel',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'is_read' => false,
    ];

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
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
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
     * Notifications addressed to the members of one cohort.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        $cohortId = $cohort instanceof Cohort ? $cohort->getKey() : $cohort;

        return $query->whereIn(
            'user_id',
            Enrollment::query()->where('cohort_id', $cohortId)->select('user_id'),
        );
    }

    /**
     * A notification is personal: not even an administrator lists someone else's
     * inbox outside the audited preview mode (BR-22).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
