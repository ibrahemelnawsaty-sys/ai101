<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One delivery of the closing project. Versioning follows the same rule as
 * assignment submissions: a new version never removes the previous one (BR-19).
 * Its existence completes journey step 7.
 *
 * @see BR-19, BR-21, BR-22, BR-23 · PRD §7.6, §9.14 · PROJECT-CONTRACT §4, §9
 */
class ProjectSubmission extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'project_submissions';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'final_project_id',
        'user_id',
        'files',
        'github_url',
        'description',
        'submitted_at',
        'is_late',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'files' => 'array',
            'is_late' => 'boolean',
            'version' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, int|bool>
     */
    protected $attributes = [
        'version' => 1,
        'is_late' => false,
    ];

    // --------------------------------------------------------- relationships

    public function finalProject(): BelongsTo
    {
        return $this->belongsTo(FinalProject::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'entity_id')
            ->where('entity_type', EvaluationEntity::FinalProject->value);
    }

    /**
     * The most recent mark, with the entity_type constraint applied to the
     * one-of-many sub-query as well.
     */
    public function latestEvaluation(): HasOne
    {
        return $this->hasOne(Evaluation::class, 'entity_id')
            ->where('entity_type', EvaluationEntity::FinalProject->value)
            ->ofMany(
                ['evaluated_at' => 'max'],
                static fn (Builder $query): Builder => $query
                    ->where('entity_type', EvaluationEntity::FinalProject->value)
            );
    }

    // ---------------------------------------------------------------- scopes

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
            'final_project_id',
            FinalProject::query()->where('cohort_id', $cohortId)->select('id')
        );
    }

    /**
     * BR-22, BR-23.
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
                'final_project_id',
                FinalProject::query()
                    ->whereIn('cohort_id', $user->accessibleCohortIds())
                    ->select('id')
            );
        }

        return $query->where('user_id', $user->getKey());
    }
}
