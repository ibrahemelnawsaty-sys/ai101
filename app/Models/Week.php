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
 * One of the four training weeks of a cohort. `index` runs 1..4 and drives
 * journey steps 3 to 6.
 *
 * @see BR-21 · PRD §7.3, §9.7 · PROJECT-CONTRACT §4, §9
 */
class Week extends Model
{
    /** @use HasFactory<\Database\Factories\WeekFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'weeks';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'index',
        'title',
        'objectives',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'index' => 'integer',
            'objectives' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
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
     * @return HasMany<Session, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @return HasMany<\App\Models\Resource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /**
     * Weeks of the cohorts the account belongs to.
     *
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
