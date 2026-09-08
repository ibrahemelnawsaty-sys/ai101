<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationEntity;
use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One delivery of an assignment by one participant. Re-delivery inserts a new row
 * with an incremented `version` and never deletes the previous one (BR-19).
 *
 * @see BR-18, BR-19, BR-22, BR-23 · PRD §7.5, §9.11 · PROJECT-CONTRACT §4
 */
class Submission extends Model
{
    /** @use HasFactory<\Database\Factories\SubmissionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'submissions';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'assignment_id',
        'user_id',
        'files',
        'github_url',
        'note',
        'submitted_at',
        'is_late',
        'version',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
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

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Evaluations recorded against this submission. `entity_type` is part of the
     * key, so the constraint is carried on the relation itself.
     *
     * @return HasMany<Evaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'entity_id')
            ->where('entity_type', EvaluationEntity::Assignment->value);
    }

    /**
     * The most recent mark. The entity_type constraint is applied to the
     * one-of-many sub-query as well, so a revision never resolves across types.
     *
     * @return HasOne<Evaluation, $this>
     */
    public function latestEvaluation(): HasOne
    {
        return $this->hasOne(Evaluation::class, 'entity_id')
            ->where('entity_type', EvaluationEntity::Assignment->value)
            ->ofMany(
                ['evaluated_at' => 'max'],
                static fn (Builder $query): Builder => $query
                    ->where('entity_type', EvaluationEntity::Assignment->value),
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
            'assignment_id',
            Assignment::query()->where('cohort_id', $cohortId)->select('id'),
        );
    }

    /**
     * The newest version delivered by each participant for one assignment.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestVersions(Builder $query): Builder
    {
        return $query->orderByDesc('version');
    }

    /**
     * BR-22: a participant reaches only their own deliveries and files.
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
                'assignment_id',
                Assignment::query()
                    ->whereIn('cohort_id', $user->accessibleCohortIds())
                    ->select('id'),
            );
        }

        return $query->where('user_id', $user->getKey());
    }
}
