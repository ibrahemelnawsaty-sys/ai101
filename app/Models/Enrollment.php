<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of one account in one cohort. This row is the assignment record that
 * BR-22 and BR-23 are enforced against; the pair (cohort_id, user_id) is unique.
 *
 * @see BR-20, BR-22, BR-23 · PRD §7.2 · PROJECT-CONTRACT §4
 */
class Enrollment extends Model
{
    /** @use HasFactory<\Database\Factories\EnrollmentFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'enrollments';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'user_id',
        'role_in_cohort',
        'enrolled_at',
        'status',
        'final_score',
        'attendance_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_in_cohort' => EnrollmentRole::class,
            'status' => EnrollmentStatus::class,
            'enrolled_at' => 'datetime',
            'final_score' => 'decimal:2',
            'attendance_rate' => 'decimal:2',
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
        return $query->where('cohort_id', $cohort instanceof Cohort ? $cohort->getKey() : $cohort);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    /**
     * A request still waiting for an administrator's decision — the ONE
     * definition the review panel, the decision endpoints and the list's
     * "pending" badge share, so the panel cannot open on a row the endpoints
     * refuse, or the reverse (D-69).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Pending->value);
    }

    public function awaitsDecision(): bool
    {
        return $this->getAttribute('status') === EnrollmentStatus::Pending;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeParticipants(Builder $query): Builder
    {
        return $query->where('role_in_cohort', EnrollmentRole::Participant->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTrainers(Builder $query): Builder
    {
        return $query->where('role_in_cohort', EnrollmentRole::Trainer->value);
    }

    /**
     * BR-22: a participant only ever sees their own enrollment row.
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
