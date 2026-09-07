<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The closing project of a cohort. It stays locked until a trainer unlocks it, and
 * the lock is enforced here in the data access layer: `scopeVisibleTo` removes the
 * row entirely for a participant while `is_unlocked` is false, so its brief and
 * requirements can never be serialised to the browser early (BR-15, BR-16).
 *
 * @see BR-11, BR-15, BR-16, BR-22, BR-23 · PRD §7.6, §9.14 · PROJECT-CONTRACT §4
 */
class FinalProject extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'final_projects';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'title',
        'brief',
        'requirements',
        'is_unlocked',
        'unlocked_at',
        'unlocked_by',
        'due_at',
        'max_score',
        'attachments',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `requirements` is a json column (PRD §7.6); without this cast the
            // array reaches PDO raw and every insert dies on SQLite.
            'requirements' => 'array',
            'is_unlocked' => 'boolean',
            'unlocked_at' => 'datetime',
            'due_at' => 'datetime',
            'max_score' => 'integer',
            'attachments' => 'array',
        ];
    }

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'is_unlocked' => false,
    ];

    // --------------------------------------------------------- relationships

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ProjectSubmission::class);
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnlocked(Builder $query): Builder
    {
        return $query->where('is_unlocked', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereIn(
            'cohort_id',
            Enrollment::query()->where('user_id', $userId)->select('cohort_id')
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
     * BR-15, BR-16: a locked project is not merely hidden in the interface — it is
     * not selected at all for a participant.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $query->whereIn('cohort_id', $user->accessibleCohortIds());

        if ($user->isParticipant()) {
            $query->where('is_unlocked', true);
        }

        return $query;
    }
}
