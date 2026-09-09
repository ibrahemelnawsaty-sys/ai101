<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personal details of one account: four-part Arabic and English names, phone,
 * gender and optional biographical fields.
 *
 * @see BR-22 · PRD §7.1, §9.4 · PROJECT-CONTRACT §4
 */
class Profile extends Model
{
    /** @use HasFactory<\Database\Factories\ProfileFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'first_name_ar',
        'second_name_ar',
        'third_name_ar',
        'last_name_ar',
        'first_name_en',
        'second_name_en',
        'third_name_en',
        'last_name_en',
        'phone',
        'gender',
        'avatar_url',
        'birth_date',
        'city',
        'education_level',
        'job_title',
        'bio',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'birth_date' => 'date',
        ];
    }

    /**
     * Four-part Arabic name, single spaced. Never contains literal text.
     *
     * @return Attribute<string, never>
     */
    protected function fullNameAr(): Attribute
    {
        return Attribute::get(fn (): string => $this->joinNames([
            $this->first_name_ar,
            $this->second_name_ar,
            $this->third_name_ar,
            $this->last_name_ar,
        ]));
    }

    /**
     * Four-part Latin name, single spaced.
     *
     * @return Attribute<string, never>
     */
    protected function fullNameEn(): Attribute
    {
        return Attribute::get(fn (): string => $this->joinNames([
            $this->first_name_en,
            $this->second_name_en,
            $this->third_name_en,
            $this->last_name_en,
        ]));
    }

    /**
     * First and family name only — the pair shown on the public verify pages (BR-25).
     *
     * @return Attribute<string, never>
     */
    protected function shortNameAr(): Attribute
    {
        return Attribute::get(fn (): string => $this->joinNames([
            $this->first_name_ar,
            $this->last_name_ar,
        ]));
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function joinNames(array $parts): string
    {
        return implode(' ', array_filter(array_map(
            static fn (?string $part): string => trim((string) $part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'user_id',
            Enrollment::query()->where('cohort_id', $cohortId)->select('user_id'),
        );
    }

    /**
     * BR-22 / BR-23 tenancy: own profile, or profiles of the trainer's cohort members.
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
