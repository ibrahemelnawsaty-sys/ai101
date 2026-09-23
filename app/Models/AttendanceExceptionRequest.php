<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceExceptionStatus;
use App\Enums\AttendanceExceptionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A participant's request to be excused for an absence or an unexcused
 * lateness on one attendance record, and the coordinator's, trainer's or
 * admin's decision on it.
 *
 * Timestamps are written from App\Services\Time\Clock only (BR-07).
 *
 * @see D-106 · PRD §9.9
 */
class AttendanceExceptionRequest extends Model
{
    use HasUuids;

    protected $table = 'attendance_exception_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'attendance_id',
        'user_id',
        'type',
        'reason',
        'status',
        'decision_reason',
        'decided_by',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttendanceExceptionType::class,
            'status' => AttendanceExceptionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
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
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AttendanceExceptionStatus::Pending->value);
    }

    /**
     * Every request for one cohort, reached through the attendance record it
     * excuses — the same nested-subquery shape Attendance::scopeForCohort()
     * already uses, so a coordinator's queue is scoped exactly like every
     * other attendance-staff query in the platform (BR-23).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        $cohortId = $cohort instanceof Cohort ? $cohort->getKey() : $cohort;

        return $query->whereIn('attendance_id', Attendance::query()->forCohort($cohortId)->select('id'));
    }
}
