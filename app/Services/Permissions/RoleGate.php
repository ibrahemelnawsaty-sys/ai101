<?php

declare(strict_types=1);

namespace App\Services\Permissions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\PermissionException;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * The service-layer role check. Policies, controllers and jobs ask this class;
 * nothing decides a permission by reading `users.role` on its own.
 *
 *  · BR-28 the check runs on the server on every request - there is no cached
 *    verdict that outlives a request, and a hidden button is not a permission
 *  · allow-lists only: a role that is not named is refused, and a call with no
 *    role named at all is refused too, because "everyone" is never a rule
 *  · fail closed: an inactive, locked or soft-deleted account holds no role
 *  · BR-32 the platform always keeps at least one active administrator
 *
 * @see BR-22, BR-23, BR-28, BR-32 · PRD §4.1, §4.2, §4.3, §4.4 · art. 5, art. 22
 */
final class RoleGate
{
    public const ADMIN = 'admin';

    public const TRAINER = 'trainer';

    public const PARTICIPANT = 'participant';

    public function __construct(
        private readonly RoleResolver $roles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * True when the account holds at least one of the named roles. An empty
     * list is always false: a gate with no allow-list guards nothing.
     */
    public function allows(User $user, string ...$roles): bool
    {
        if ($roles === []) {
            return false;
        }

        $requested = array_values(array_filter(
            array_map(static fn (string $role): string => strtolower(trim($role)), $roles),
            static fn (string $role): bool => $role !== '',
        ));

        if ($requested === []) {
            return false;
        }

        return $this->roles->hasAnyRole($user, $requested);
    }

    /**
     * @throws PermissionException
     */
    public function assert(User $user, string ...$roles): void
    {
        $this->assertActive($user);

        if ($this->allows($user, ...$roles)) {
            return;
        }

        $this->audit->denied('role.denied', 'user', (string) $user->getKey(), [
            'required' => array_values($roles),
        ]);

        throw PermissionException::roleRequired();
    }

    /**
     * @throws PermissionException
     */
    public function assertActive(User $user): void
    {
        if ($this->roles->isActive($user)) {
            return;
        }

        $this->audit->denied('account.inactive', 'user', (string) $user->getKey());

        throw PermissionException::inactiveAccount();
    }

    public function isAdmin(User $user): bool
    {
        return $this->allows($user, self::ADMIN);
    }

    public function isTrainer(User $user): bool
    {
        return $this->allows($user, self::TRAINER);
    }

    public function isParticipant(User $user): bool
    {
        return $this->allows($user, self::PARTICIPANT);
    }

    /**
     * BR-23 - a trainer over one specific cohort.
     */
    public function isTrainerOf(User $user, ?string $cohortId): bool
    {
        return $this->roles->isTrainerOf($user, $cohortId);
    }

    /**
     * BR-22 - a participant of one specific cohort.
     */
    public function isParticipantOf(User $user, ?string $cohortId): bool
    {
        return $this->roles->isParticipantOf($user, $cohortId);
    }

    /**
     * @throws PermissionException
     */
    public function assertTrainerOf(User $user, ?string $cohortId): void
    {
        if ($this->isAdmin($user) || $this->isTrainerOf($user, $cohortId)) {
            return;
        }

        $this->audit->denied('cohort.trainer_denied', 'cohort', $cohortId);

        throw PermissionException::cohortOutOfScope();
    }

    /**
     * BR-32 - would demoting, suspending or deleting this account leave the
     * platform without a single active administrator?
     */
    public function isLastActiveAdmin(User $user): bool
    {
        if ($user->role !== UserRole::Admin || ! $this->roles->isActive($user)) {
            return false;
        }

        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }

    /**
     * @throws PermissionException
     */
    public function assertNotLastActiveAdmin(User $user): void
    {
        if (! $this->isLastActiveAdmin($user)) {
            return;
        }

        $this->audit->denied('admin.last_active', 'user', (string) $user->getKey());

        throw PermissionException::forbidden();
    }
}
