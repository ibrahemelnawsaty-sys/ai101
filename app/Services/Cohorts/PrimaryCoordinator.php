<?php

declare(strict_types=1);

namespace App\Services\Cohorts;

use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
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
 * @see D-124 · D-105 · CONSTITUTION art. 6, art. 7
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

    /**
     * The accounts actively coordinating this cohort, in the order they were
     * seated.
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
}
