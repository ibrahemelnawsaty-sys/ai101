<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A platform account: administrator, trainer or participant.
 *
 * The password column is `password_hash` (never `password`) and is never
 * serialised. Email verification and password reset both run through the
 * `email_tokens` table, not through Laravel's signed-URL notifications.
 *
 * A `datetime` cast is asymmetric: it reads back as CarbonImmutable but accepts
 * any DateTimeInterface or date string on write, which is what
 * markEmailAsVerifiedAt() hands it.
 *
 * @property-read CarbonImmutable|null $email_verified_at
 * @property-write \DateTimeInterface|string|null $email_verified_at
 *
 * @see BR-22, BR-23, BR-28, BR-29, BR-30, BR-32 · PRD §7.1, §4.1 · PROJECT-CONTRACT §4
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
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
        // An account created FOR someone: it signs in once with a temporary
        // password and cannot go anywhere until that password is replaced.
        'must_change_password',
        'temp_password_expires_at',
        'invited_at',
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
            'must_change_password' => 'boolean',
            'temp_password_expires_at' => 'datetime',
            'invited_at' => 'datetime',
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
        throw new \RuntimeException(
            'Use markEmailAsVerifiedAt() with an instant from App\Services\Time\Clock (BR-07).',
        );
    }

    /**
     * Verification is driven by the EmailToken flow with an explicit server instant,
     * so no framework clock is consulted here (BR-07).
     */
    public function markEmailAsVerifiedAt(\DateTimeInterface $at): bool
    {
        $this->email_verified_at = $at;

        return $this->save();
    }

    /**
     * Laravel's built-in verification mail is deliberately unavailable: the platform
     * issues single-use tokens from the `email_tokens` table instead.
     */
    public function sendEmailVerificationNotification(): void
    {
        throw new \RuntimeException(
            'Email verification is issued through App\Models\EmailToken, not Laravel notifications.',
        );
    }

    /**
     * Laravel's built-in reset mail is deliberately unavailable for the same reason.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        throw new \RuntimeException(
            'Password resets are issued through App\Models\EmailToken, not Laravel notifications.',
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
    public function isLockedAt(\DateTimeInterface $at): bool
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

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return BelongsToMany<Cohort, $this>
     */
    public function cohorts(): BelongsToMany
    {
        return $this->belongsToMany(Cohort::class, 'enrollments')
            ->withPivot(['role_in_cohort', 'status', 'enrolled_at', 'final_score', 'attendance_rate'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function editedAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'edited_by');
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * @return HasMany<ProjectSubmission, $this>
     */
    public function projectSubmissions(): HasMany
    {
        return $this->hasMany(ProjectSubmission::class);
    }

    /**
     * @return HasMany<Evaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    /**
     * @return HasMany<Evaluation, $this>
     */
    public function evaluationsGiven(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'evaluated_by');
    }

    /**
     * @return HasMany<Session, $this>
     */
    public function trainedSessions(): HasMany
    {
        return $this->hasMany(Session::class, 'trainer_id');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function createdAssignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'created_by');
    }

    /**
     * @return HasMany<\App\Models\Resource, $this>
     */
    public function uploadedResources(): HasMany
    {
        return $this->hasMany(Resource::class, 'uploaded_by');
    }

    /**
     * @return HasMany<FinalProject, $this>
     */
    public function unlockedFinalProjects(): HasMany
    {
        return $this->hasMany(FinalProject::class, 'unlocked_by');
    }

    /**
     * @return HasMany<UserJourneyState, $this>
     */
    public function journeyStates(): HasMany
    {
        return $this->hasMany(UserJourneyState::class);
    }

    /**
     * @return HasMany<ThreadParticipant, $this>
     */
    public function threadParticipations(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class);
    }

    /**
     * @return BelongsToMany<Thread, $this>
     */
    public function threads(): BelongsToMany
    {
        return $this->belongsToMany(Thread::class, 'thread_participants')
            ->withPivot(['last_read_at', 'is_muted'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * @return HasMany<Thread, $this>
     */
    public function createdThreads(): HasMany
    {
        return $this->hasMany(Thread::class, 'created_by');
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function issuedCertificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'issued_by');
    }

    /**
     * @return HasMany<DigitalCard, $this>
     */
    public function digitalCards(): HasMany
    {
        return $this->hasMany(DigitalCard::class);
    }

    /**
     * @return HasMany<EmailToken, $this>
     */
    public function emailTokens(): HasMany
    {
        return $this->hasMany(EmailToken::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    /**
     * @return HasMany<ImpersonationSession, $this>
     */
    public function impersonationsPerformed(): HasMany
    {
        return $this->hasMany(ImpersonationSession::class, 'admin_id');
    }

    /**
     * @return HasMany<ImpersonationSession, $this>
     */
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
            Enrollment::query()->where('cohort_id', $cohortId)->select('user_id'),
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
                    ->select('user_id'),
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
                ->select('user_id'),
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
