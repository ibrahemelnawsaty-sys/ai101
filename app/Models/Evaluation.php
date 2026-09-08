<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded score with mandatory feedback. `feedback` is never shorter than ten
 * characters (BR-13) and `revision_reason` is required whenever an already
 * recorded score is changed (BR-14). Both are enforced in the FormRequest and by
 * database constraints, not here.
 *
 * `entity_type` is a plain enum column, not a Laravel morph alias, so the two
 * possible subjects are exposed as separate relations plus a resolving accessor.
 *
 * @see BR-12, BR-13, BR-14, BR-22, BR-23 · PRD §7.5, §9.15 · PROJECT-CONTRACT §4, §7
 */
class Evaluation extends Model
{
    /** @use HasFactory<\Database\Factories\EvaluationFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Minimum feedback length, BR-13.
     */
    public const MIN_FEEDBACK_LENGTH = 10;

    protected $table = 'evaluations';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'entity_type',
        'entity_id',
        'user_id',
        'score',
        'max_score',
        'feedback',
        'evaluated_by',
        'evaluated_at',
        'revision_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => EvaluationEntity::class,
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'evaluated_at' => 'datetime',
        ];
    }

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    /**
     * Valid only when `entity_type` is `assignment`.
     *
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'entity_id');
    }

    /**
     * Valid only when `entity_type` is `final_project`.
     *
     * @return BelongsTo<ProjectSubmission, $this>
     */
    public function projectSubmission(): BelongsTo
    {
        return $this->belongsTo(ProjectSubmission::class, 'entity_id');
    }

    /**
     * The evaluated artefact, resolved from `entity_type`.
     *
     * The match is exhaustive over EvaluationEntity on purpose: a `default` arm
     * would be unreachable today and would silently return null the day a third
     * kind of evaluated artefact is added, instead of failing the build here.
     * Either relation still resolves to null when the row it points at is gone.
     */
    public function subject(): Submission|ProjectSubmission|null
    {
        return match ($this->entity_type) {
            EvaluationEntity::Assignment => $this->submission,
            EvaluationEntity::FinalProject => $this->projectSubmission,
        };
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, EvaluationEntity $type): Builder
    {
        return $query->where('entity_type', $type->value);
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
     * Evaluations belonging to the members of one cohort.
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
     * BR-22: a participant reaches only their own marks.
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
                'user_id',
                Enrollment::query()
                    ->whereIn('cohort_id', $user->accessibleCohortIds())
                    ->select('user_id'),
            );
        }

        return $query->where('user_id', $user->getKey());
    }
}
