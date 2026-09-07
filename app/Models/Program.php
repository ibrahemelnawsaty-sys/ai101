<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProgramStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A training program. Its editable content lives in the database and is managed
 * from the admin panel, never written in code (BR-31, BR-36).
 *
 * @see BR-31, BR-36 · PRD §7.2 · PROJECT-CONTRACT §4
 */
class Program extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'programs';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Route model binding uses the slug on public pages.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @var list<string> */
    protected $fillable = [
        'name_ar',
        'name_en',
        'slug',
        'description',
        'banner_url',
        'objectives',
        'target_audience',
        'certificates',
        'hours',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProgramStatus::class,
            'objectives' => 'array',
            'target_audience' => 'array',
            'certificates' => 'array',
            'hours' => 'integer',
        ];
    }

    public function cohorts(): HasMany
    {
        return $this->hasMany(Cohort::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ProgramStatus::Published->value);
    }

    /**
     * Programs a given account is enrolled in or teaches.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereIn(
            'id',
            Cohort::query()
                ->whereIn(
                    'id',
                    Enrollment::query()->where('user_id', $userId)->select('cohort_id')
                )
                ->select('program_id')
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        $cohortId = $cohort instanceof Cohort ? $cohort->getKey() : $cohort;

        return $query->whereIn(
            'id',
            Cohort::query()->whereKey($cohortId)->select('program_id')
        );
    }

    /**
     * BR-23: a trainer or participant only sees programs behind a cohort they reach.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn(
            'id',
            Cohort::query()
                ->whereIn('id', $user->accessibleCohortIds())
                ->select('program_id')
        );
    }
}
