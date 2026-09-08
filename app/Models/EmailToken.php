<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailTokenType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use email token for account verification or password reset. Only the
 * hash is stored, never the token itself, and a row is spent the moment `used_at`
 * is written.
 *
 * Every instant is supplied by the caller from App\Services\Time\Clock, so this
 * model never reads a clock of its own (BR-07).
 *
 * `expires_at` is NOT NULL in the migration, but a column is still absent — and
 * therefore null — on an instance that was never hydrated from a full row: a
 * freshly constructed model, or one fetched by a query whose select() left the
 * column out. hasExpiredAt() guards for exactly that and fails closed, so the
 * read type has to admit null (CONSTITUTION Article 7).
 *
 * A `datetime` cast is asymmetric: it reads back as CarbonImmutable but accepts
 * any DateTimeInterface or date string on write, which is what markUsedAt() hands it.
 *
 * @property-read CarbonImmutable|null $expires_at
 * @property-write \DateTimeInterface|string $expires_at
 * @property-read CarbonImmutable|null $used_at
 * @property-write \DateTimeInterface|string|null $used_at
 *
 * @see BR-07, BR-29, BR-30 · PRD §7.6, §9.2, §9.3 · PROJECT-CONTRACT §4
 */
class EmailToken extends Model
{
    /** @use HasFactory<\Database\Factories\EmailTokenFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'email_tokens';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'token_hash',
        'type',
        'expires_at',
        'used_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EmailTokenType::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function hasExpiredAt(\DateTimeInterface $at): bool
    {
        return $this->expires_at === null
            || $this->expires_at->getTimestamp() <= $at->getTimestamp();
    }

    /**
     * A token is usable only when it is neither spent nor expired. Anything the
     * model cannot decide fails closed (Constitution, Article 7).
     */
    public function isUsableAt(\DateTimeInterface $at): bool
    {
        return ! $this->isUsed() && ! $this->hasExpiredAt($at);
    }

    public function markUsedAt(\DateTimeInterface $at): bool
    {
        $this->used_at = $at;

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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, EmailTokenType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Rows still usable at the given server instant.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsableAt(Builder $query, \DateTimeInterface $at): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', $at);
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
     * Tokens are credentials: they are never listed for another account, not even
     * by an administrator (Constitution, Article 7).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
