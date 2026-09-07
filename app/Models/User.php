<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use DateTimeInterface;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use RuntimeException;

/**
 * A platform account: administrator, trainer or participant.
 *
 * The password column is `password_hash` (never `password`) and is never
 * serialised. Email verification and password reset both run through the
 * `email_tokens` table, not through Laravel's signed-URL notifications.
 *
 * @see BR-22, BR-23, BR-28, BR-29, BR-30, BR-32 · PRD §7.1, §4.1 · PROJECT-CONTRACT §4
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'password_hash',
        'role',
        'status',
        'email_verified_at',
        'locale',
        'last_login_at',
        'failed_login_count',
        'locked_until',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password_hash',
        'password',
        'remember_token',
    ];

    /**
     * Memoised cohort ids this account may reach.
     *
     * @var array<int, string>|null
     */
    protected ?array $accessibleCohortIdsCache = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'password_hash' => 'hashed',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_login_count' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ auth

    /**
     * The stored hash. Laravel's default `password` column is not used here.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('password_hash');
    }

    /**
     * Column Laravel compares against and rehashes.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * True once the address has been confirmed. Laravel's `verified` middleware
     * asks this, and asks nothing else.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->getAttribute('email_verified_at') !== null;
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->getAttribute('email');
    }

    /**
     * Deliberately unavailable: the framework's version stamps the row from
     * its own clock, and the only clock on this platform is
     * App\Services\Time\Clock (BR-07). Callers use markEmailAsVerifiedAt().
     */
    public function markEmailAsVerified(): bool
    {
        throw new RuntimeException(
            'Use markEmailAsVerifiedAt() with an instant from App\Services\Time\Clock (BR-07).'
        );
    }

    /**
     * Verification is driven by the EmailToken flow with an explicit server instant,
     * so no framework clock is consulted here (BR-07).
     */
    public function markEmailAsVerifiedAt(DateTimeInterface $at): bool
    {
        $this->email_verified_at = $at;

        return $this->save();
    }

    /**
     * Laravel's built-in verification mail is deliberately unavailable: the platform
     * issues single-use tokens from the `email_tokens` table instead.
     */
    public function sendEmailVerificationNotification()
    {
        throw new RuntimeException(
            'Email verification is issued through App\Models\EmailToken, not Laravel notifications.'
        );
    }

    /**
     * Laravel's built-in reset mail is deliberately unavailable for the same reason.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token)
    {
        throw new RuntimeException(
            'Password resets are issued through App\Models\EmailToken, not Laravel notifications.'
        );
    }

    // ----------------------------------------------------------------- roles

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isTrainer(): bool
    {
        return $this->role === UserRole::Trainer;
    }

    public function isParticipant(): bool
    {
        return $this->role === UserRole::Participant;
    }

    /**
     * An account allowed to authenticate at all (BR-32 keeps one active admin).
     *
     * `deleted_at` is read off the loaded attributes rather than through the
     * magic getter, because it is not part of an INSERT: a row just created
     * carries no such attribute, and asking for it threw
     * MissingAttributeException — a 500 on any screen that asked whether the
     * account was active (the journey screen did). Absent means not deleted, and
     * that is sound rather than a fail-open: the soft-delete global scope means
     * an ordinary query never returns a trashed account in the first place, and
     * a withTrashed() query selects the column, so a trashed row always arrives
     * with its stamp attached.
     *
     * @see CONSTITUTION.md Article 7 · BR-32 · PRD §7.8
     */
    public function isActive(): bool
    {
        $deletedAt = $this->getAttributes()[$this->getDeletedAtColumn()] ?? null;

        return $this->status === UserStatus::Active && $deletedAt === null;
    }

    /**
     * Temporary lock after repeated failed logins. The instant is supplied by the
     * caller, which must read it from App\Services\Time\Clock (BR-07).
     */
    public function isLockedAt(DateTimeInterface $at): bool
    {
        return $this->locked_until !== null
            && $this->locked_until->getTimestamp() > $at->getTimestamp();
    }

    /**
     * Cohort ids this account may reach at all (BR-22, BR-23). Administrators are
     * unrestricted and never reach this method.
     *
     * @return array<int, string>
     */
    public function accessibleCohortIds(): array
    {
        if ($this->accessibleCohortIdsCache !== null) {
            return $this->accessibleCohortIdsCache;
        }

        $ids = Enrollment::query()
            ->where('user_id', $this->getKey())
            ->pluck('cohort_id')
            ->all();

        if ($this->isTrainer()) {
            $ids = array_merge($ids, Session::query()
                ->where('trainer_id', $this->getKey())
                ->pluck('cohort_id')
                ->all());
        }

        /** @var array<int, string> $unique */
        $unique = array_values(array_unique(array_filter($ids)));

        return $this->accessibleCohortIdsCache = $unique;
    }

    // --------------------------------------------------------- relationships

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function cohorts(): BelongsToMany
    {
        return $this->belongsToMany(Cohort::class, 'enrollments')
            ->withPivot(['role_in_cohort', 'status', 'enrolled_at', 'final_score', 'attendance_rate'])
            ->withTimestamps();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function editedAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'edited_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function projectSubmissions(): HasMany
    {
        return $this->hasMany(ProjectSubmission::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function evaluationsGiven(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'evaluated_by');
    }

    public function trainedSessions(): HasMany
    {
        return $this->hasMany(Session::class, 'trainer_id');
    }

    public function createdAssignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'created_by');
    }

    public function uploadedResources(): HasMany
    {
        return $this->hasMany(Resource::class, 'uploaded_by');
    }

    public function unlockedFinalProjects(): HasMany
    {
        return $this->hasMany(FinalProject::class, 'unlocked_by');
    }

    public function journeyStates(): HasMany
    {
        return $this->hasMany(UserJourneyState::class);
    }

    public function threadParticipations(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class);
    }

    public function threads(): BelongsToMany
    {
        return $this->belongsToMany(Thread::class, 'thread_participants')
            ->withPivot(['last_read_at', 'is_muted'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function createdThreads(): HasMany
    {
        return $this->hasMany(Thread::class, 'created_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function issuedCertificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'issued_by');
    }

    public function digitalCards(): HasMany
    {
        return $this->hasMany(DigitalCard::class);
    }

    public function emailTokens(): HasMany
    {
        return $this->hasMany(EmailToken::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    public function impersonationsPerformed(): HasMany
    {
        return $this->hasMany(ImpersonationSession::class, 'admin_id');
    }

    public function impersonationsReceived(): HasMany
    {
        return $this->hasMany(ImpersonationSession::class, 'target_id');
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        return $query->whereKey($user instanceof self ? $user->getKey() : $user);
    }

    /**
     * Members of one cohort, whatever their role in it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCohort(Builder $query, Cohort|string $cohort): Builder
    {
        $cohortId = $cohort instanceof Cohort ? $cohort->getKey() : $cohort;

        return $query->whereIn(
            'id',
            Enrollment::query()->where('cohort_id', $cohortId)->select('user_id')
        );
    }

    /**
     * Tenancy gate (BR-22, BR-23): a participant sees only themselves, a trainer sees
     * only members of the cohorts assigned to them, an administrator sees everyone.
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
                'id',
                Enrollment::query()
                    ->whereIn('cohort_id', $user->accessibleCohortIds())
                    ->select('user_id')
            );
        }

        return $query->whereKey($user->getKey());
    }

    /**
     * Trainers assigned to a cohort through an enrollment row.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTrainersOfCohort(Builder $query, Cohort|string $cohort): Builder
    {
        $cohortId = $cohort instanceof Cohort ? $cohort->getKey() : $cohort;

        return $query->whereIn(
            'id',
            Enrollment::query()
                ->where('cohort_id', $cohortId)
                ->where('role_in_cohort', EnrollmentRole::Trainer->value)
                ->select('user_id')
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }
}
