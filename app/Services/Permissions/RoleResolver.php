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
 * @see BR-22, BR-23, BR-28 · PRD §4.1, §4.2, §4.3, §4.4 · CONSTITUTION Art. 22
 */
final class RoleResolver
{
    /** @var array<string, list<string>> */
    private array $cohortIdCache = [];

    /** @var array<string, bool> */
    private array $roleCache = [];

    public function globalRole(User $user): UserRole
    {
        return $user->role;
    }

    public function isAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin;
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

        if ($user->role !== UserRole::Admin) {
            if ($this->trainerCohortIds($user) !== []) {
                $roles[] = UserRole::Trainer->value;
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
     * says that an account IS a trainer. `users.role` decides that, and only an
     * administrator may change it (PRD Â§4.2). Once it is changed the next
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

    /** @return list<string> */
    public function participantCohortIds(User $user): array
    {
        return $this->cohortIds($user, EnrollmentRole::Participant);
    }

    public function isTrainerOf(User $user, ?string $cohortId): bool
    {
        if ($cohortId === null || ! $this->isActive($user)) {
            return false;
        }

        return in_array($cohortId, $this->trainerCohortIds($user), true);
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
