<?php

declare(strict_types=1);

namespace App\Services\Permissions;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\User;

/**
 * The single source of truth for "which role does this user hold, and over
 * which cohorts". Every Policy and every scoping middleware asks this class
 * rather than reading `users.role` directly, because a person may be a trainer
 * in one cohort and a participant in another (PRD §4.4).
 *
 * Answers are memoised per request only — never cached across requests, because
 * every permission is re-checked on every request (BR-28).
 *
 * @see BR-22, BR-23, BR-28, BR-32 · PRD §4.1, §4.2, §4.3, §4.4 · CONSTITUTION Art. 22 · D-117
 */
final class RoleResolver
{
    /**
     * BR-32 as D-117 restates it: the two roles the platform may never run out
     * of. The general supervisor runs the programme and the system
     * administrator runs the accounts; once the last active holder of either is
     * gone, nobody in the interface can give the role back.
     *
     * @var list<UserRole>
     */
    public const GUARDED_ROLES = [UserRole::Admin, UserRole::SystemAdmin];

    /** @var array<string, list<string>> */
    private array $cohortIdCache = [];

    /** @var array<string, bool> */
    private array $roleCache = [];

    public function globalRole(User $user): UserRole
    {
        return $user->role;
    }

    /**
     * Which shell this account gets: 'admin', 'system_admin', 'trainer',
     * 'coordinator' or 'participant'.
     *
     * The one statement of the precedence the dashboard, the rail and the
     * layout composer all need — the two administrative roles first, by their
     * own role column; a trainer who is not also a participant; everyone else a
     * participant. It was written out in two places and was about to be a third
     * (D-75). The system administrator has a shell of its own (D-117): falling
     * through to 'participant' would have served an account manager the
     * trainee's rail, home and notification list.
     */
    public function shellRole(User $user): string
    {
        if ($this->isAdmin($user)) {
            return 'admin';
        }

        if ($this->isSystemAdmin($user)) {
            return 'system_admin';
        }

        if (! $this->hasRole($user, 'participant') && $this->hasRole($user, 'trainer')) {
            return 'trainer';
        }

        if (! $this->hasRole($user, 'participant') && $this->hasRole($user, 'coordinator')) {
            return 'coordinator';
        }

        return 'participant';
    }

    /** The general supervisor (D-117) — what PRD §4.1 calls the system administrator. */
    public function isAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /** The system administrator: accounts, account preview, landing page (D-117). */
    public function isSystemAdmin(User $user): bool
    {
        return $user->role === UserRole::SystemAdmin;
    }

    /**
     * BR-32 (D-117): would taking this account out of its role — demoting,
     * suspending or deleting it — leave the platform with no ACTIVE holder of
     * a role it may never run out of?
     *
     * The subject's own status is deliberately not consulted: what matters is
     * whether SOMEONE ELSE could still act in that role afterwards. Asked
     * fresh on every call and never memoised — a request that changes one
     * account must see the other accounts as they are now (BR-28).
     *
     * `$lock` is for the write itself, inside its transaction: it locks EVERY
     * active holder's row — the subject's included — before counting, so two
     * holders removing each other at the same moment cannot both see the
     * other one still there. The second waits for the first and then counts
     * one. Without the subject's own row in the lock, each request would lock
     * a different row and both would pass.
     */
    public function isLastActiveHolder(User $subject, bool $lock = false): bool
    {
        if (! in_array($subject->role, self::GUARDED_ROLES, true)) {
            return false;
        }

        $query = User::query()
            ->where('role', $subject->role->value)
            ->where('status', UserStatus::Active->value);

        if (! $lock) {
            return $query->whereKeyNot($subject->getKey())->doesntExist();
        }

        $holders = $query->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_diff($holders, [(string) $subject->getKey()])) === [];
    }

    public function isActive(User $user): bool
    {
        return $user->status === UserStatus::Active;
    }

    /**
     * Every role the user effectively holds: the global role on the account,
     * plus any role carried by an active enrollment (PRD §4.4).
     *
     * @return list<string>
     */
    public function effectiveRoles(User $user): array
    {
        $roles = [$user->role->value];

        // Neither administrative role takes anything from an enrolment row: the
        // supervisor already reaches every cohort, and the system administrator
        // reaches none (D-117) — a leftover row from before a role change must
        // not hand an account manager a trainee's or a trainer's screens.
        if (! in_array($user->role, self::GUARDED_ROLES, true)) {
            if ($this->trainerCohortIds($user) !== []) {
                $roles[] = UserRole::Trainer->value;
            }

            if ($this->coordinatorCohortIds($user) !== []) {
                $roles[] = UserRole::Coordinator->value;
            }

            if ($this->participantCohortIds($user) !== []) {
                $roles[] = UserRole::Participant->value;
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * Allow-list check. An inactive account holds no role at all (fail closed).
     */
    public function hasRole(User $user, string $role): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        $key = $user->getKey().'|'.$role;

        return $this->roleCache[$key] ??= in_array($role, $this->effectiveRoles($user), true);
    }

    /**
     * @param  list<string>  $roles
     */
    public function hasAnyRole(User $user, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($user, $role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cohorts this account works in AS A TRAINER.
     *
     * An enrolment says WHICH cohorts a trainer is responsible for; it never
     * says that an account IS a trainer. `users.role` decides that, and only the
     * system administrator may change it (PRD Â§4.2, D-117). Once it is changed the next
     * request must already feel it (BR-28), so a leftover
     * `enrollments.role_in_cohort = 'trainer'` cannot hand the powers back -
     * that would be privilege escalation out of a data row, and it would make
     * the administrator's own control over roles decorative.
     *
     * The reverse direction is untouched, and it is the one PRD Â§4.4 actually
     * describes: a trainer account may still be a participant in another cohort,
     * because that is a step down, not up.
     *
     * @return list<string>
     */
    public function trainerCohortIds(User $user): array
    {
        if ($user->role !== UserRole::Trainer && $user->role !== UserRole::Admin) {
            return [];
        }

        return $this->cohortIds($user, EnrollmentRole::Trainer);
    }

    /**
     * The cohorts this account sits in AS A PARTICIPANT. Any role may be a
     * trainee somewhere (PRD §4.4) — except the system administrator, whose
     * role reaches no cohort at all (D-117), leftover enrolment or not.
     *
     * @return list<string>
     */
    public function participantCohortIds(User $user): array
    {
        if ($user->role === UserRole::SystemAdmin) {
            return [];
        }

        return $this->cohortIds($user, EnrollmentRole::Participant);
    }

    /**
     * The cohorts this account works in AS A COORDINATOR — mirrors
     * trainerCohortIds() exactly, including the primary-role gate: only an
     * account whose own role is Coordinator (or Admin) can ever hold this
     * authority, so a demoted account cannot keep it through a leftover
     * enrolment row.
     *
     * @return list<string>
     */
    public function coordinatorCohortIds(User $user): array
    {
        if ($user->role !== UserRole::Coordinator && $user->role !== UserRole::Admin) {
            return [];
        }

        return $this->cohortIds($user, EnrollmentRole::Coordinator);
    }

    public function isTrainerOf(User $user, ?string $cohortId): bool
    {
        if ($cohortId === null || ! $this->isActive($user)) {
            return false;
        }

        return in_array($cohortId, $this->trainerCohortIds($user), true);
    }

    public function isCoordinatorOf(User $user, ?string $cohortId): bool
    {
        if ($cohortId === null || ! $this->isActive($user)) {
            return false;
        }

        return in_array($cohortId, $this->coordinatorCohortIds($user), true);
    }

    public function isParticipantOf(User $user, ?string $cohortId): bool
    {
        if ($cohortId === null || ! $this->isActive($user)) {
            return false;
        }

        return in_array($cohortId, $this->participantCohortIds($user), true);
    }

    /**
     * Cohorts this user may read operational data for.
     * Admins are handled by the caller: `null` here means "no restriction".
     *
     * @return list<string>
     */
    public function readableCohortIds(User $user): array
    {
        if (! $this->isActive($user)) {
            return [];
        }

        return array_values(array_unique(array_merge(
            $this->trainerCohortIds($user),
            $this->coordinatorCohortIds($user),
            $this->participantCohortIds($user),
        )));
    }

    /**
     * True when the user may see anything belonging to the given cohort,
     * in any capacity. Admins see every cohort (PRD §4.2).
     */
    public function canReachCohort(User $user, ?string $cohortId): bool
    {
        if ($cohortId === null || ! $this->isActive($user)) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        return in_array($cohortId, $this->readableCohortIds($user), true);
    }

    /** Drop memoised answers — used after an enrollment changes mid-request. */
    public function forget(User $user): void
    {
        $prefix = (string) $user->getKey();

        foreach (array_keys($this->cohortIdCache) as $key) {
            if (str_starts_with($key, $prefix.'|')) {
                unset($this->cohortIdCache[$key]);
            }
        }

        foreach (array_keys($this->roleCache) as $key) {
            if (str_starts_with($key, $prefix.'|')) {
                unset($this->roleCache[$key]);
            }
        }
    }

    /** @return list<string> */
    private function cohortIds(User $user, EnrollmentRole $role): array
    {
        $key = $user->getKey().'|'.$role->value;

        if (isset($this->cohortIdCache[$key])) {
            return $this->cohortIdCache[$key];
        }

        /** @var list<string> $ids */
        $ids = Enrollment::query()
            ->where('user_id', $user->getKey())
            ->where('role_in_cohort', $role->value)
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
            ->pluck('cohort_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return $this->cohortIdCache[$key] = $ids;
    }
}
