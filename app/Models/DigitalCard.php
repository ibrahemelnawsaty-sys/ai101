<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The participant's digital card. `qr_token` is a long signed value and is hidden
 * from serialisation; the public verify page reached through it shows no sensitive
 * personal data (BR-25).
 *
 * A `datetime` cast is asymmetric: it reads back as CarbonImmutable but accepts
 * any DateTimeInterface or date string on write, which is what markRevokedAt()
 * hands it, so `revoked_at` is the one column spelled out here.
 *
 * @property-read CarbonImmutable|null $revoked_at
 * @property-write \DateTimeInterface|string|null $revoked_at
 *
 * @see BR-22, BR-25 · PRD §7.6, §9.6 · PROJECT-CONTRACT §4
 */
class DigitalCard extends Model
{
    /** @use HasFactory<\Database\Factories\DigitalCardFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'digital_cards';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'cohort_id',
        'card_number',
        'qr_token',
        'issued_at',
        'revoked_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'qr_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Revocation instant is supplied by the caller from Clock::now() (BR-07).
     */
    public function markRevokedAt(\DateTimeInterface $at): bool
    {
        $this->revoked_at = $at;

        return $this->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Cohort, $this>
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
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
        return $query->where('cohort_id', $cohort instanceof Cohort ? $cohort->getKey() : $cohort);
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
            return $query->whereIn('cohort_id', $user->accessibleCohortIds());
        }

        return $query->where('user_id', $user->getKey());
    }
}
