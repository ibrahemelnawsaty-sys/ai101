<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One run of a program. Carries the two certificate thresholds that
 * CertificateEligibility reads: pass_score and min_attendance_rate.
 *
 * @see BR-22, BR-23, BR-26, BR-31 · PRD §7.2 · PROJECT-CONTRACT §4
 */
class Cohort extends Model
{
    /** @use HasFactory<\Database\Factories\CohortFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Contract defaults for a newly created cohort (PROJECT-CONTRACT §4).
     */
    public const DEFAULT_PASS_SCORE = 60;

    public const DEFAULT_MIN_ATTENDANCE_RATE = 75;

    protected $table = 'cohorts';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'program_id',
        'name',
        'start_date',
        'end_date',
        'capacity',
        'registration_closes_at',
        'seats_taken',
        'status',
        'pass_score',
        'min_attendance_rate',
        'requires_approval',
    ];

    /**
     * `requires_approval` is a boolean column, so the default map is not
     * uniformly integer-valued.
     *
     * @var array<string, int|bool>
     */
    protected $attributes = [
        'seats_taken' => 0,
        'pass_score' => self::DEFAULT_PASS_SCORE,
        'min_attendance_rate' => self::DEFAULT_MIN_ATTENDANCE_RATE,
        'requires_approval' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CohortStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_closes_at' => 'datetime',
            'capacity' => 'integer',
            'seats_taken' => 'integer',
            'pass_score' => 'integer',
            'min_attendance_rate' => 'integer',
            'requires_approval' => 'boolean',
        ];
    }

    /**
     * Free seats, never negative. Registration also depends on the closing instant,
     * which is evaluated by the registration service against Clock::now().
     */
    public function seatsRemaining(): int
    {
        return max(0, (int) $this->capacity - (int) $this->seats_taken);
    }

    // --------------------------------------------------------- relationships

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrollments')
            ->withPivot(['role_in_cohort', 'status', 'enrolled_at', 'final_score', 'attendance_rate'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->users()->wherePivot('role_in_cohort', EnrollmentRole::Participant->value);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function trainers(): BelongsToMany
    {
        return $this->users()->wherePivot('role_in_cohort', EnrollmentRole::Trainer->value);
    }

    /**
     * @return HasMany<Week, $this>
     */
    public function weeks(): HasMany
    {
        return $this->hasMany(Week::class);
    }

    /**
     * @return HasMany<Session, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @return HasOne<FinalProject, $this>
     */
    public function finalProject(): HasOne
    {
        return $this->hasOne(FinalProject::class);
    }

    /**
     * @return HasMany<\App\Models\Resource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /**
     * @return HasMany<JourneyStep, $this>
     */
    public function journeySteps(): HasMany
    {
        return $this->hasMany(JourneyStep::class);
    }

    /**
     * @return HasMany<Thread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return HasMany<DigitalCard, $this>
     */
    public function digitalCards(): HasMany
    {
        return $this->hasMany(DigitalCard::class);
    }

    /**
     * @return HasOne<LandingSetting, $this>
     */
    public function landingSetting(): HasOne
    {
        return $this->hasOne(LandingSetting::class);
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereIn(
            'id',
            Enrollment::query()->where('user_id', $userId)->select('cohort_id'),
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        return $query->whereKey($cohort instanceof self ? $cohort->getKey() : $cohort);
    }

    /**
     * BR-22, BR-23: only cohorts the account is enrolled in or assigned to.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('id', $user->accessibleCohortIds());
    }
}
