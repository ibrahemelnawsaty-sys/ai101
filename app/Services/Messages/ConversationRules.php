<?php

declare(strict_types=1);

namespace App\Services\Messages;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Permissions\RoleResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may START a conversation with whom (D-118) — the one statement of the
 * owner's rule. The recipient picker lists exactly `recipients()`, and the
 * start endpoint asks `mayStartWith()`, which is the same query narrowed to
 * one account: what the screen offers and what the server accepts can never
 * disagree.
 *
 *   system administrator → a general supervisor (the conversation lands in
 *                          the administrators' shared inbox)
 *   general supervisor   → the shared inbox · any trainer, coordinator or
 *                          trainee
 *   coordinator          → a general supervisor · the trainers and trainees
 *                          of their own cohorts
 *   trainer              → the coordinators of their own cohorts
 *   trainee              → the trainers and coordinators of their own cohorts
 *
 * Replying is not governed here: any member of an existing conversation
 * replies (ThreadPolicy::post). That is how "the trainer replies but does not
 * start" holds — a trainee opens the conversation, the trainer answers in it.
 *
 * An account counts in a cohort by the same enrolment rows RoleResolver reads
 * (active or completed), and only while its own role matches the capacity: a
 * leftover trainer row on a demoted account makes nobody a trainer (D-117).
 * Recipients are active accounts whose holder has signed in: an invitation
 * not yet accepted has nobody to read the message (D-119).
 *
 * @see BR-22, BR-23, BR-28 · PRD §9.13 · CONSTITUTION Art. 5, Art. 22 · D-117, D-118, D-119
 */
final class ConversationRules
{
    public function __construct(private readonly RoleResolver $roles) {}

    /**
     * The accounts $from may start a conversation with, the shared inbox
     * aside (see mayWriteToInbox). Empty for an inactive account.
     *
     * @return Builder<User>
     */
    public function recipients(User $from): Builder
    {
        $query = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->whereKeyNot($from->getKey());

        if (! $this->roles->isActive($from)) {
            return $query->whereIn('id', []);
        }

        if ($this->roles->isSystemAdmin($from)) {
            return $query->where('role', UserRole::Admin->value);
        }

        if ($this->roles->isAdmin($from)) {
            return $query->whereIn('role', [
                UserRole::Trainer->value,
                UserRole::Coordinator->value,
                UserRole::Participant->value,
            ]);
        }

        $asCoordinator = $this->roles->coordinatorCohortIds($from);
        $asTrainer = $this->roles->trainerCohortIds($from);
        $asParticipant = $this->roles->participantCohortIds($from);

        $isCoordinator = $from->role === UserRole::Coordinator;

        return $query->where(function (Builder $allowed) use ($isCoordinator, $asCoordinator, $asTrainer, $asParticipant): void {
            // Nothing matches until a capacity below adds its own branch.
            $allowed->whereIn('id', []);

            // "The coordinator writes to the general supervisor" names no
            // cohort: a coordinator not yet assigned one still reaches them.
            if ($isCoordinator) {
                $allowed->orWhere('role', UserRole::Admin->value);
            }

            if ($asCoordinator !== []) {
                $allowed
                    ->orWhere(fn (Builder $q) => $this->holding($q, UserRole::Trainer, EnrollmentRole::Trainer, $asCoordinator))
                    ->orWhere(fn (Builder $q) => $this->seatedIn($q, $asCoordinator));
            }

            if ($asTrainer !== []) {
                $allowed->orWhere(fn (Builder $q) => $this->holding($q, UserRole::Coordinator, EnrollmentRole::Coordinator, $asTrainer));
            }

            if ($asParticipant !== []) {
                $allowed
                    ->orWhere(fn (Builder $q) => $this->holding($q, UserRole::Trainer, EnrollmentRole::Trainer, $asParticipant))
                    ->orWhere(fn (Builder $q) => $this->holding($q, UserRole::Coordinator, EnrollmentRole::Coordinator, $asParticipant));
            }
        });
    }

    public function mayStartWith(User $from, User $to): bool
    {
        return $this->recipients($from)->whereKey($to->getKey())->exists();
    }

    /** Only a general supervisor writes to the system administrators' inbox. */
    public function mayWriteToInbox(User $from): bool
    {
        return $this->roles->isActive($from) && $this->roles->isAdmin($from);
    }

    /**
     * Accounts whose own role is $role and who hold $capacity in one of
     * $cohortIds.
     *
     * @param  Builder<User>  $query
     * @param  list<string>  $cohortIds
     */
    private function holding(Builder $query, UserRole $role, EnrollmentRole $capacity, array $cohortIds): void
    {
        $query->where('role', $role->value)
            ->whereIn('id', $this->enrolled($capacity, $cohortIds));
    }

    /**
     * Trainees of $cohortIds: any account seated there as a participant —
     * any role may be a trainee somewhere (PRD §4.4) — except the two
     * administrative roles, which take nothing from an enrolment (D-117).
     *
     * @param  Builder<User>  $query
     * @param  list<string>  $cohortIds
     */
    private function seatedIn(Builder $query, array $cohortIds): void
    {
        $query->whereNotIn('role', [UserRole::Admin->value, UserRole::SystemAdmin->value])
            ->whereIn('id', $this->enrolled(EnrollmentRole::Participant, $cohortIds));
    }

    /**
     * @param  list<string>  $cohortIds
     * @return Builder<Enrollment>
     */
    private function enrolled(EnrollmentRole $capacity, array $cohortIds): Builder
    {
        return Enrollment::query()
            ->select('user_id')
            ->where('role_in_cohort', $capacity->value)
            ->whereIn('cohort_id', $cohortIds)
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value]);
    }
}
