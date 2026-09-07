<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The append-only audit trail. Constitution Article 8 and BR-27: a row may be
 * inserted and read, never changed and never removed.
 *
 * Three layers enforce that here: `update()`, `delete()` and a re-`save()` of an
 * existing row all throw; the `updating` and `deleting` model events throw as well,
 * which also covers relation-driven and mass paths. The final layer is outside this
 * file — the production database user holds no UPDATE or DELETE grant on this table.
 *
 * The table carries `created_at` only; `UPDATED_AT` is disabled because a row is
 * never touched again.
 *
 * @see BR-10, BR-14, BR-27, BR-35 · PRD §7.6, §7.8 · CONSTITUTION Article 8
 */
class AuditLog extends Model
{
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    /** @var list<string> */
    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * Event-level guard, so mass and relation-driven paths throw too.
     */
    protected static function booted(): void
    {
        static::updating(static function (self $log): void {
            throw new RuntimeException('audit_logs is append-only: an entry can never be updated.');
        });

        static::deleting(static function (self $log): void {
            throw new RuntimeException('audit_logs is append-only: an entry can never be deleted.');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     * @return never
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException('audit_logs is append-only: an entry can never be updated.');
    }

    /**
     * @return never
     */
    public function delete()
    {
        throw new RuntimeException('audit_logs is append-only: an entry can never be deleted.');
    }

    /**
     * Inserting is allowed; re-saving an existing entry is not.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('audit_logs is append-only: an entry can never be updated.');
        }

        return parent::save($options);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Entries recorded by one actor.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        return $query->where('actor_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Entries recorded by the members of one cohort.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        $cohortId = $cohort instanceof Cohort ? $cohort->getKey() : $cohort;

        return $query->whereIn(
            'actor_id',
            Enrollment::query()->where('cohort_id', $cohortId)->select('user_id')
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    /**
     * Only an administrator reads the trail; everyone else gets an empty set.
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
