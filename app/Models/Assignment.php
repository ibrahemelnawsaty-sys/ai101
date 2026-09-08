<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A graded task set by a trainer. The trainer decides whether it is mandatory,
 * its maximum score, its deadline and whether late delivery is accepted (BR-17).
 *
 * @see BR-11, BR-17, BR-18, BR-22, BR-23 · PRD §7.5, §9.11 · PROJECT-CONTRACT §4, §7
 */
class Assignment extends Model
{
    /** @use HasFactory<\Database\Factories\AssignmentFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'assignments';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'week_id',
        'title',
        'description',
        'is_mandatory',
        'max_score',
        'due_at',
        'allow_late',
        'allow_github_link',
        'max_file_size_mb',
        'max_files',
        'attachments',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'is_mandatory' => 'boolean',
            'allow_late' => 'boolean',
            'allow_github_link' => 'boolean',
            'max_score' => 'integer',
            'max_file_size_mb' => 'integer',
            'max_files' => 'integer',
            'attachments' => 'array',
            'due_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === AssignmentStatus::Published;
    }

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<Cohort, $this>
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * @return BelongsTo<Week, $this>
     */
    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Published->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMandatory(Builder $query): Builder
    {
        return $query->where('is_mandatory', true);
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
     * BR-23 for trainers, and a draft assignment never reaches a participant.
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
            $query->where('status', AssignmentStatus::Published->value);
        }

        return $query;
    }
}
