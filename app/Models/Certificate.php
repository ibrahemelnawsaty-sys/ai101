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
 * An issued certificate. Both `serial_number` and `verify_code` are unique;
 * `verify_code` is a long random signed value, never a user identifier.
 *
 * A revoked certificate keeps its row: the public verify page reports it as
 * revoked rather than as missing.
 *
 * Every column type is derived from the migration and the casts() map by the
 * static analyser itself; only `revoked_at` is spelled out, because a
 * `datetime` cast is asymmetric — it reads back as CarbonImmutable but accepts
 * any DateTimeInterface or date string on write, which is what markRevokedAt()
 * hands it.
 *
 * @property-read CarbonImmutable|null $revoked_at
 * @property-write \DateTimeInterface|string|null $revoked_at
 *
 * @see BR-25, BR-26 · PRD §7.6, §9.17 · PROJECT-CONTRACT §4, §8
 */
class Certificate extends Model
{
    /** @use HasFactory<\Database\Factories\CertificateFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'certificates';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'cohort_id',
        'serial_number',
        'verify_code',
        'issued_at',
        'issued_by',
        'file_url',
        'final_score',
        'attendance_rate',
        'revoked_at',
        'tvtc_file_url',
    ];

    /** @var list<string> */
    protected $hidden = [
        'file_url',
        'tvtc_file_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'final_score' => 'decimal:2',
            'attendance_rate' => 'decimal:2',
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

    // --------------------------------------------------------- relationships

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
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    // ---------------------------------------------------------------- scopes

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
     * BR-22, BR-23. The public verify page does not use this scope: it looks the
     * certificate up by code and exposes only the fields BR-25 allows.
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
