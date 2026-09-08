<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance record for one account in one session. The pair
 * (session_id, user_id) is unique in the database, which is what actually
 * enforces BR-06; the application check is only a friendlier message.
 *
 * Timestamps are written from App\Services\Time\Clock only (BR-07).
 *
 * @see BR-01 … BR-10, BR-22, BR-23 · PRD §7.4, §9.9 · PROJECT-CONTRACT §4, §6
 */
class Attendance extends Model
{
    /** @use HasFactory<\Database\Factories\AttendanceFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'attendances';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'session_id',
        'user_id',
        'check_in_at',
        'check_out_at',
        'status',
        'is_manual',
        'edited_by',
        'edit_reason',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'is_manual' => 'boolean',
        ];
    }

    /**
     * @var array<string, bool>
     */
    protected $attributes = [
        'is_manual' => false,
    ];

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    // ---------------------------------------------------------------- scopes

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
            'session_id',
            Session::query()->where('cohort_id', $cohortId)->select('id'),
        );
    }

    /**
     * @param  Builder<self>  $query
     * @param  array<int, AttendanceStatus>  $statuses
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, array $statuses): Builder
    {
        return $query->whereIn(
            'status',
            array_map(static fn (AttendanceStatus $status): string => $status->value, $statuses),
        );
    }

    /**
     * BR-22: a participant never reaches another participant's attendance record.
     * BR-23: a trainer only reaches records of the cohorts assigned to them.
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
                'session_id',
                Session::query()
                    ->whereIn('cohort_id', $user->accessibleCohortIds())
                    ->select('id'),
            );
        }

        return $query->where('user_id', $user->getKey());
    }
}
