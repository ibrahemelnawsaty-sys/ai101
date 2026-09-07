<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JourneyStepStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one participant stands on one journey step. The pair
 * (user_id, journey_step_id) is unique. Rows are written only by
 * App\Services\Journey\JourneyEvaluator from real data (BR-21).
 *
 * @see BR-20, BR-21, BR-22 · PRD §7.6, §9.7 · PROJECT-CONTRACT §4, §9
 */
class UserJourneyState extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'user_journey_states';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'journey_step_id',
        'status',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JourneyStepStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function journeyStep(): BelongsTo
    {
        return $this->belongsTo(JourneyStep::class);
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
            'journey_step_id',
            JourneyStep::query()->where('cohort_id', $cohortId)->select('id')
        );
    }

    /**
     * BR-22: a participant sees only their own journey.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isTrainer()) {
            return $query->whereIn(
                'journey_step_id',
                JourneyStep::query()
                    ->whereIn('cohort_id', $user->accessibleCohortIds())
                    ->select('id')
            );
        }

        return $query->where('user_id', $user->getKey());
    }
}
