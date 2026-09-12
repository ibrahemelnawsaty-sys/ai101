<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who currently receives a letter addressed to a cohort.
 *
 * WHY THE ROSTER IS RESOLVED HERE AND NOT CARRIED BY THE EVENT
 * Listeners are queued. A list of recipients captured when a trainer pressed
 * "publish" is a list from minutes ago: somebody may have withdrawn, been
 * suspended, or had their enrolment revoked in between. Chasing a withdrawn
 * participant for work they no longer owe is not a cosmetic error — it is the
 * platform telling somebody something untrue about their own standing.
 *
 * So the event carries a cohort id and this resolves the roster at send time.
 *
 * THE SCOPE IS THE POINT. `role_in_cohort` and `status` are both constrained:
 * without the first a letter for participants reaches the trainers, and without
 * the second it reaches people whose enrolment was withdrawn. This is the same
 * scoping rule the screens follow (art. 5), applied to outbound mail.
 *
 * @see BR-23 · CONSTITUTION.md Article 5, Article 20 · D-51
 */
final class CohortAudience
{
    /**
     * Active participants of one cohort, each with the profile the greeting
     * needs — eager loaded, because a letter per person must not become a
     * query per person (art. 20).
     *
     * @return Collection<int, User>
     */
    public function participants(string $cohortId): Collection
    {
        return User::query()
            ->with('profile')
            ->whereIn(
                'id',
                Enrollment::query()
                    ->where('cohort_id', $cohortId)
                    ->where('role_in_cohort', EnrollmentRole::Participant->value)
                    ->where('status', EnrollmentStatus::Active->value)
                    ->select('user_id'),
            )
            // An account that is suspended or pending has no business being
            // written to about coursework.
            ->where('status', 'active')
            ->get();
    }

    /**
     * The addresses to write to, skipping anyone without one rather than
     * failing the whole batch for a single incomplete record.
     *
     * @return Collection<int, User>
     */
    public function reachable(string $cohortId, ?string $type = null): Collection
    {
        $users = $this->participants($cohortId)
            ->filter(fn (User $user): bool => (string) $user->getAttribute('email') !== '')
            ->values();

        // A letter type, when given, drops everyone who switched e-mail off for
        // it. Every cohort-wide sender passes its type; before D-66 none did,
        // and the preferences screen changed nothing that was ever sent.
        return $type === null ? $users : app(MailPreferences::class)->filter($users, $type);
    }

    /**
     * The reachable participants who have handed in NO version of this
     * assignment — the whole audience of "remind who has not submitted"
     * (FR-ASGN-30), and nobody else. Resolved at send time like the rest of
     * this class (D-51), so someone who submits between the press and the
     * queue is not chased for work already in.
     *
     * @return Collection<int, User>
     */
    public function yetToSubmit(string $cohortId, string $assignmentId, ?string $type = null): Collection
    {
        $handedIn = Submission::query()
            ->where('assignment_id', $assignmentId)
            ->distinct()
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $this->reachable($cohortId, $type)
            ->reject(static fn (User $user): bool => in_array((string) $user->getKey(), $handedIn, true))
            ->values();
    }
}
