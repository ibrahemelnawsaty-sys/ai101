<?php

declare(strict_types=1);

namespace App\Services\Cohorts;

use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\User;

/**
 * Who a cohort's primary coordinator is (D-124) — the one reader of
 * `cohorts.primary_coordinator_id`.
 *
 * The owner's rule: every cohort has a coordinator; with several, the general
 * supervisor chooses the primary; a single coordinator is primary on their
 * own. So the answer is the chosen coordinator while they are still an ACTIVE
 * coordinator of the cohort, otherwise the only active coordinator, otherwise
 * nobody — never someone who has left, whatever the column still says.
 *
 * A support ticket reaches the primary coordinator first; a cohort with
 * nobody here sends its tickets to the general supervisor instead, so none is
 * lost (the safety net D-124 asked for).
 *
 * @see D-124 · D-105 · BR-23 · SCR-9.18 · CONSTITUTION art. 6, art. 7
 */
final class PrimaryCoordinator
{
    /**
     * The states a cohort may enter only with a primary coordinator: open for
     * registration, and running (D-124).
     *
     * @var list<CohortStatus>
     */
    public const REQUIRED_FOR = [CohortStatus::Open, CohortStatus::Running];

    /** departureRefusal(): the cohort would be left with no coordinator. */
    public const REFUSED_LAST = 'last';

    /** departureRefusal(): the primary coordinator, while a choice remains. */
    public const REFUSED_PRIMARY = 'primary';

    /**
     * The account roles that can hold a coordinator's powers — mirrors
     * RoleResolver::coordinatorCohortIds().
     *
     * @var list<string>
     */
    private const COORDINATING_ROLES = [UserRole::Coordinator->value, UserRole::Admin->value];

    /**
     * The accounts actively coordinating this cohort, in the order they were
     * seated: an active enrolment as coordinator, held by an ACTIVE account
     * whose own role can coordinate — the same gate RoleResolver puts on the
     * coordinator's powers. A suspended account, or one moved to another role,
     * keeps its enrolment row but could not act on a ticket routed to it, so it
     * is nobody's primary coordinator and counts for none of the rules.
     *
     * @return list<string>
     */
    public function coordinatorIds(Cohort $cohort): array
    {
        /** @var list<string> $ids */
        $ids = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Coordinator->value)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereIn('user_id', User::query()
                ->where('status', UserStatus::Active->value)
                ->whereIn('role', self::COORDINATING_ROLES)
                ->select('id'))
            ->orderBy('enrolled_at')
            ->orderBy('id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    /** The primary coordinator's account id, or null when the cohort has none. */
    public function idOf(Cohort $cohort): ?string
    {
        $coordinators = $this->coordinatorIds($cohort);
        $chosen = $cohort->getAttribute('primary_coordinator_id');

        if (is_string($chosen) && in_array($chosen, $coordinators, true)) {
            return $chosen;
        }

        return count($coordinators) === 1 ? $coordinators[0] : null;
    }

    public function of(Cohort $cohort): ?User
    {
        $id = $this->idOf($cohort);

        if ($id === null) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->with('profile')->find($id);

        return $user;
    }

    public function has(Cohort $cohort): bool
    {
        return $this->idOf($cohort) !== null;
    }

    public function isCoordinatorOf(Cohort $cohort, string $userId): bool
    {
        return in_array($userId, $this->coordinatorIds($cohort), true);
    }

    /**
     * Why this account may not stop coordinating the cohort now — or null when
     * it may (D-124). The last coordinator who can act never leaves
     * (REFUSED_LAST); the primary one leaves only once no choice remains, one
     * coordinator left and primary on their own (REFUSED_PRIMARY). Asked by
     * every door a coordinator leaves through: removing them, and assigning
     * them to the same cohort as a trainer, which rewrites the same enrolment
     * row. Each door words the refusal for its own form.
     */
    public function departureRefusal(Cohort $cohort, string $userId): ?string
    {
        $coordinators = $this->coordinatorIds($cohort);

        if (! in_array($userId, $coordinators, true)) {
            return null;
        }

        $remaining = count($coordinators) - 1;

        if ($remaining === 0) {
            return self::REFUSED_LAST;
        }

        if ($remaining > 1 && $this->idOf($cohort) === $userId) {
            return self::REFUSED_PRIMARY;
        }

        return null;
    }
}
