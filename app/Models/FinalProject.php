<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The closing project of a cohort. It stays locked until a trainer unlocks it, and
 * the lock is enforced here in the data access layer: `scopeVisibleTo` removes the
 * row entirely for a participant while `is_unlocked` is false, so its brief and
 * requirements can never be serialised to the browser early (BR-15, BR-16).
 *
 * What a participant hands in is not fixed here: the administrator defines it
 * field by field (`fields()`, D-121), and the fields are part of the brief —
 * they reach a participant only after the unlock, like everything else.
 *
 * @see BR-11, BR-15, BR-16, BR-22, BR-23 · PRD §7.6, §9.14 · PROJECT-CONTRACT §4 · D-121
 */
class FinalProject extends Model
{
    /** @use HasFactory<\Database\Factories\FinalProjectFactory> */
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
        'allow_late',
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
            // BR-18's own switch, mirrored from Assignment: off refuses a late
            // hand-in outright, on accepts it marked late (D-110).
            'allow_late' => 'boolean',
            'max_score' => 'integer',
            'attachments' => 'array',
        ];
    }

    /**
     * Whether the deadline has passed at `$at` — the same strict, UTC
     * comparison Assignment::isPastDueAt() uses, so a submission and its
     * reminder never disagree about the boundary (D-110).
     */
    public function isPastDueAt(\DateTimeInterface $at): bool
    {
        $dueAt = $this->due_at;

        return $dueAt !== null && Clock::toUtc($at)->greaterThan(Clock::toUtc($dueAt));
    }

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'is_unlocked' => false,
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
    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    /**
     * @return HasMany<ProjectSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(ProjectSubmission::class);
    }

    /**
     * The hand-in form's fields, in the order the administrator set (D-121).
     *
     * @return HasMany<FinalProjectField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FinalProjectField::class)->ordered();
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
            Enrollment::query()->where('user_id', $userId)->select('cohort_id'),
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
