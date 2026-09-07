<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editable landing-page content for one cohort: hero copy, FAQ entries, the
 * countdown switch and the registration switch. All of it is managed from the
 * admin panel and never written in code (BR-31).
 *
 * @see BR-31, BR-36 · PRD §7.6, §9.1 · PROJECT-CONTRACT §4
 */
class LandingSetting extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'landing_settings';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'seats_remaining_override',
        'countdown_enabled',
        'hero_text',
        'hero_title',
        'about_body',
        'faq',
        'is_registration_open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats_remaining_override' => 'integer',
            'countdown_enabled' => 'boolean',
            'is_registration_open' => 'boolean',
            'faq' => 'array',
        ];
    }

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'countdown_enabled' => false,
        'is_registration_open' => false,
    ];

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * Landing settings belong to a cohort, not to a person; the row of the cohorts
     * the account reaches is returned so trainers can preview their own landing.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereIn(
            'cohort_id',
            Enrollment::query()->where('user_id', $userId)->select('cohort_id')
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
     * Only an administrator manages landing content. Anyone else gets an empty set,
     * failing closed rather than open (Constitution, Article 7).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('id', []);
    }
}
