<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SessionStatus;
use App\Enums\SessionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scheduled training session.
 *
 * `date` is a DATE column and `start_time` / `end_time` are TIME columns holding
 * wall-clock Riyadh times as `HH:MM:SS` strings. They are deliberately NOT cast to
 * dates: only App\Services\Time\Clock may combine them into an instant, and only
 * App\Services\Attendance\AttendanceWindow may derive the check-in and check-out
 * windows from them (BR-01 … BR-07).
 *
 * `zoom_url` and `zoom_passcode` are hidden from serialisation as defence in depth
 * for BR-24; the authoritative gate stays in the controller and policy.
 *
 * @see BR-01, BR-04, BR-07, BR-22, BR-23, BR-24 · PRD §7.3, §9.9 · PROJECT-CONTRACT §4, §6
 */
class Session extends Model
{
    /** @use HasFactory<\Database\Factories\SessionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'cohort_id',
        'week_id',
        'title',
        'topic',
        'description',
        'type',
        'date',
        'start_time',
        'end_time',
        'trainer_id',
        'zoom_url',
        'zoom_passcode',
        'join_opens_minutes',
        'recording_url',
        'status',
        'cancellation_reason',
    ];

    /** @var list<string> */
    protected $hidden = [
        'zoom_url',
        'zoom_passcode',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SessionType::class,
            'status' => SessionStatus::class,
            'date' => 'date',
            'start_time' => 'string',
            'end_time' => 'string',
            // Null means the trainer expressed no preference and the platform
            // default applies — it is not zero, and casting must not turn it
            // into one (D-52).
            'join_opens_minutes' => 'integer',
        ];
    }

    /**
     * A cancelled session never accepts attendance (PROJECT-CONTRACT §6).
     */
    public function isCancelled(): bool
    {
        return $this->status === SessionStatus::Cancelled;
    }

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<Cohort, $this>
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * @return BelongsTo<Week, $this>
     */
    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<\App\Models\Resource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, SessionType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Sessions of the cohorts the account belongs to.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereIn(
            'cohort_id',
            Enrollment::query()->where('user_id', $userId)->select('cohort_id'),
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
     * BR-23: a trainer only reaches sessions of the cohorts assigned to them.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('cohort_id', $user->accessibleCohortIds());
    }
}
