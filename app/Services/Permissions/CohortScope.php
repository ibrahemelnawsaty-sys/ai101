<?php

declare(strict_types=1);

namespace App\Services\Permissions;

use App\Exceptions\PermissionException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Cohort tenancy. The one place that answers "which cohorts may this account
 * touch", and the one place that narrows a query to them.
 *
 *  · BR-22 a participant reaches their own rows and nothing else
 *  · BR-23 a trainer reaches the cohorts assigned to them and nothing else
 *  · BR-28 the answer is recomputed on every request; nothing is cached
 *    between requests and no answer is ever taken from the interface
 *
 * Two rules that reviewers must not "simplify" away:
 *
 *  1. `null` from readableCohortIds() means "no restriction" and is reserved
 *     for administrators. An empty array means "no cohort at all" and is what a
 *     trainer with no assignment gets - those two are opposites, so the code
 *     never conflates them with a falsy check.
 *  2. apply() on a restricted user with an empty list narrows the query to
 *     nothing (whereRaw of a false condition through whereIn on an empty set),
 *     it does not silently return every row.
 *
 * @see BR-22, BR-23, BR-28 · PRD §4.2, §4.3 · CONSTITUTION art. 5, art. 22
 */
final class CohortScope
{
    public function __construct(
        private readonly RoleResolver $roles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Administrators are unrestricted (PRD §4.2); everyone else is scoped.
     */
    public function isUnrestricted(User $user): bool
    {
        return $this->roles->isAdmin($user) && $this->roles->isActive($user);
    }

    /**
     * Cohorts the account may read, or null when it may read every cohort.
     *
     * @return list<string>|null
     */
    public function readableCohortIds(User $user): ?array
    {
        if ($this->isUnrestricted($user)) {
            return null;
        }

        return $this->roles->readableCohortIds($user);
    }

    /**
     * Cohorts the account may change data in, or null for an administrator.
     * A participant may never write cohort-level data, so the list is empty
     * for them even where they can read (BR-22).
     *
     * @return list<string>|null
     */
    public function writableCohortIds(User $user): ?array
    {
        if ($this->isUnrestricted($user)) {
            return null;
        }

        if (! $this->roles->isActive($user)) {
            return [];
        }

        return $this->roles->trainerCohortIds($user);
    }

    public function canRead(User $user, ?string $cohortId): bool
    {
        if ($cohortId === null || $cohortId === '') {
            return false;
        }

        $allowed = $this->readableCohortIds($user);

        return $allowed === null || in_array($cohortId, $allowed, true);
    }

    public function canWrite(User $user, ?string $cohortId): bool
    {
        if ($cohortId === null || $cohortId === '') {
            return false;
        }

        $allowed = $this->writableCohortIds($user);

        return $allowed === null || in_array($cohortId, $allowed, true);
    }

    /**
     * BR-23 - refuse and record. Changing the identifier in a link must end in
     * a refusal that leaves a trail with the caller's address.
     *
     * @throws PermissionException
     */
    public function assertCanRead(User $user, ?string $cohortId): void
    {
        if ($this->canRead($user, $cohortId)) {
            return;
        }

        $this->refuse('cohort.read_denied', 'cohort', $cohortId);
    }

    /**
     * @throws PermissionException
     */
    public function assertCanWrite(User $user, ?string $cohortId): void
    {
        if ($this->canWrite($user, $cohortId)) {
            return;
        }

        $this->refuse('cohort.write_denied', 'cohort', $cohortId);
    }

    /**
     * Narrow a query to the cohorts this account may read. Accepts an Eloquent
     * builder or a raw query builder.
     */
    public function apply(EloquentBuilder|QueryBuilder $query, User $user, string $column = 'cohort_id'): EloquentBuilder|QueryBuilder
    {
        $allowed = $this->readableCohortIds($user);

        if ($allowed === null) {
            return $query;
        }

        // An empty allow-list matches nothing: whereIn with an empty array
        // produces `0 = 1`, which is exactly the intended answer.
        $query->whereIn($column, $allowed);

        return $query;
    }

    /**
     * Narrow a query to the cohorts this account may change.
     */
    public function applyWritable(EloquentBuilder|QueryBuilder $query, User $user, string $column = 'cohort_id'): EloquentBuilder|QueryBuilder
    {
        $allowed = $this->writableCohortIds($user);

        if ($allowed === null) {
            return $query;
        }

        $query->whereIn($column, $allowed);

        return $query;
    }

    /**
     * BR-22 - horizontal access. A participant owns only their own rows; a
     * trainer and an administrator read across the accounts they may reach,
     * which is decided by the cohort scope, not by this method.
     */
    public function ownsRecord(User $user, ?string $ownerId): bool
    {
        if ($ownerId === null || $ownerId === '') {
            return false;
        }

        return (string) $user->getKey() === $ownerId;
    }

    /**
     * A participant may only ever reach their own row; anyone else needs the
     * cohort scope to agree as well.
     *
     * @throws PermissionException
     */
    public function assertOwnsOrCanRead(User $user, ?string $ownerId, ?string $cohortId): void
    {
        if ($this->ownsRecord($user, $ownerId)) {
            return;
        }

        if ($this->roles->isAdmin($user) || $this->roles->isTrainerOf($user, $cohortId)) {
            $this->assertCanRead($user, $cohortId);

            return;
        }

        $this->refuse('resource.owner_denied', 'user', $ownerId);
    }

    /**
     * The cohort a participant screen should default to: their single active
     * cohort, or null when they have none.
     */
    public function defaultParticipantCohortId(User $user): ?string
    {
        $ids = $this->roles->participantCohortIds($user);

        return $ids[0] ?? null;
    }

    /**
     * Refuse, having first written the attempt to the trail (art. 8, art. 22).
     *
     * @throws PermissionException
     */
    private function refuse(string $action, string $entityType, ?string $target): never
    {
        $this->audit->denied($action, $entityType, $target);

        throw PermissionException::cohortOutOfScope();
    }
}
