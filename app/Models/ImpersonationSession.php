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
 * One preview session: an administrator viewing another account in strict read-only
 * mode. It expires 30 minutes after it starts, it can never target another
 * administrator, and both its start and its end are written to `audit_logs`.
 *
 * The read-only enforcement itself lives in the request middleware and the data
 * access layer, not in this row — this row is the audit and expiry record.
 *
 * `started_at` is NOT NULL in the migration, but a column is still absent — and
 * therefore null — on an instance that was never hydrated from a full row: a
 * freshly constructed model, or one fetched by a query whose select() left the
 * column out. isActiveAt() guards for exactly that and fails closed, so the read
 * type has to admit null (CONSTITUTION Article 7).
 *
 * A `datetime` cast is asymmetric: it reads back as CarbonImmutable but accepts
 * any DateTimeInterface or date string on write, which is what endAt() hands it.
 *
 * @property-read CarbonImmutable|null $started_at
 * @property-write \DateTimeInterface|string $started_at
 * @property-read CarbonImmutable|null $ended_at
 * @property-write \DateTimeInterface|string|null $ended_at
 *
 * @see BR-33, BR-34, BR-35 · PRD §4.5 · CONSTITUTION Article 23 · PROJECT-CONTRACT §4
 */
class ImpersonationSession extends Model
{
    /** @use HasFactory<\Database\Factories\ImpersonationSessionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Hard ceiling on a preview session, Constitution Article 23.
     */
    public const MAX_DURATION_MINUTES = 30;

    protected $table = 'impersonation_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'admin_id',
        'target_id',
        'started_at',
        'ended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Still running at the given server instant, and still inside the 30 minute
     * ceiling. A row with no start instant is treated as finished, failing closed.
     */
    public function isActiveAt(\DateTimeInterface $at): bool
    {
        if ($this->ended_at !== null || $this->started_at === null) {
            return false;
        }

        return $at->getTimestamp() < $this->expiresAt()->getTimestamp();
    }

    /**
     * The instant this preview stops being valid, whatever the browser believes.
     *
     * A row with no start instant has no expiry to compute; isActiveAt() already
     * treats such a row as finished, so reaching this method with one means the
     * record is broken and the ceiling must not be guessed (BR-35).
     */
    public function expiresAt(): \DateTimeInterface
    {
        $startedAt = $this->started_at;

        if ($startedAt === null) {
            throw new \RuntimeException('A preview session without a start instant has no expiry.');
        }

        return $startedAt->addMinutes(self::MAX_DURATION_MINUTES);
    }

    public function endAt(\DateTimeInterface $at): bool
    {
        $this->ended_at = $at;

        return $this->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }

    /**
     * Preview sessions in which the account was the target.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        return $query->where('target_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        $cohortId = $cohort instanceof Cohort ? $cohort->getKey() : $cohort;

        return $query->whereIn(
            'target_id',
            Enrollment::query()->where('cohort_id', $cohortId)->select('user_id'),
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Only an administrator reads preview history; everyone else gets an empty set.
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
