<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the ten journey steps defined per cohort. `index` runs 1..10 in the order
 * fixed by PROJECT-CONTRACT §9. `unlock_rule` names the condition that
 * App\Services\Journey\JourneyEvaluator evaluates from real data — there is never a
 * manual completion by a participant (BR-21).
 *
 * @see BR-20, BR-21, BR-22, BR-23 · PRD §7.6, §9.7 · PROJECT-CONTRACT §4, §9
 */
class JourneyStep extends Model
{
    /** @use HasFactory<\Database\Factories\JourneyStepFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The journey is exactly ten steps long (PROJECT-CONTRACT §9).
     */
    public const TOTAL_STEPS = 10;

    protected $table = 'journey_steps';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'index',
        'title',
        'description',
        'type',
        'unlock_rule',
        'related_entity_type',
        'related_entity_id',
        'icon',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'index' => 'integer',
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
     * @return HasMany<UserJourneyState, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(UserJourneyState::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('index');
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('cohort_id', $user->accessibleCohortIds());
    }
}
