<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One delivery of the closing project. Versioning follows the same rule as
 * assignment submissions: a new version never removes the previous one (BR-19).
 * Its existence completes journey step 7.
 *
 * What was handed in lives in `answers` (D-121): one entry per field of the
 * project's hand-in form, IN THE ORDER the form showed them, each carrying a
 * copy of the field's label and type beside the value or the stored files —
 * the shape SubmissionFields::answer() writes and nothing else writes. The copy
 * is the point: the administrator may rename or remove a field tomorrow, and
 * this version must still say what was asked and what came back.
 *
 * `live_url`, `github_url`, `presentation_file`, `logo_file`, `description` and
 * `files` are the earlier, fixed hand-in (D-110 and before). They are kept as
 * they were, never written again; the D-121 migration copied every row of them
 * into `answers`.
 *
 * @see BR-19, BR-21, BR-22, BR-23 · PRD §7.6, §9.14 · PROJECT-CONTRACT §4, §9 · D-110, D-121
 */
class ProjectSubmission extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectSubmissionFactory> */
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
        'answers',
        'live_url',
        'github_url',
        'presentation_file',
        'logo_file',
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
            // D-121 — the hand-in itself, one snapshot entry per field.
            'answers' => 'array',
            // D-110's two named deliverables: single-file descriptors, the
            // same shape PrivateFileService::store() returns for one entry of
            // `files`, never a list of one (they are not a repeatable field).
            'presentation_file' => 'array',
            'logo_file' => 'array',
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
     * @return BelongsTo<FinalProject, $this>
     */
    public function finalProject(): BelongsTo
    {
        return $this->belongsTo(FinalProject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Evaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'entity_id')
            ->where('entity_type', EvaluationEntity::FinalProject->value);
    }

    /**
     * The most recent mark, with the entity_type constraint applied to the
     * one-of-many sub-query as well.
     *
     * @return HasOne<Evaluation, $this>
     */
    public function latestEvaluation(): HasOne
    {
        return $this->hasOne(Evaluation::class, 'entity_id')
            ->where('entity_type', EvaluationEntity::FinalProject->value)
            ->ofMany(
                ['evaluated_at' => 'max'],
                static fn (Builder $query): Builder => $query
                    ->where('entity_type', EvaluationEntity::FinalProject->value),
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
            FinalProject::query()->where('cohort_id', $cohortId)->select('id'),
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
                    ->select('id'),
            );
        }

        return $query->where('user_id', $user->getKey());
    }
}
