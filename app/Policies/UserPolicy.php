<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certificate;
use App\Models\Evaluation;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Accounts, roles, suspension, deletion and account preview.
 *
 * Two rules here are absolute and are re-stated in the service layer:
 * an admin never deletes their own account and the platform never drops below
 * one active admin (BR-32); an admin never previews another admin (BR-35).
 *
 * @see BR-22, BR-32, BR-33, BR-35 · PRD §4.2, §4.3, §4.5 · CONSTITUTION Art. 22
 */
final class UserPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->admin($user);
    }

    /**
     * Own account always; admins see everyone; a trainer sees the profile of a
     * participant who shares one of their cohorts, and nobody else (BR-23).
     */
    public function view(User $user, User $subject): bool
    {
        if ($this->owns($user, (string) $subject->getKey()) || $this->admin($user)) {
            return true;
        }

        foreach ($this->roles->trainerCohortIds($user) as $cohortId) {
            if ($this->roles->isParticipantOf($subject, $cohortId)) {
                return true;
            }
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function update(User $user, User $subject): bool
    {
        return $this->admin($user) && $this->writesAllowed() && $subject->status !== UserStatus::Deleted;
    }

    /** Own profile edits. Never available while previewing (BR-33). */
    public function updateOwnProfile(User $user, User $subject): bool
    {
        return $this->owns($user, (string) $subject->getKey()) && $this->writesAllowed();
    }

    /** Nobody but an admin changes a role, and never their own (PRD §4.3). */
    public function changeRole(User $user, User $subject): bool
    {
        if (! $this->admin($user) || ! $this->writesAllowed() || $user->is($subject)) {
            return false;
        }

        // BR-32: demoting the last active administrator empties the platform of
        // administrators just as surely as suspending or deleting them, so the
        // same guard belongs here - it was only in the controller before.
        return $subject->role !== UserRole::Admin || $this->otherActiveAdminsExist($subject);
    }

    public function suspend(User $user, User $subject): bool
    {
        if (! $this->admin($user) || ! $this->writesAllowed() || $user->is($subject)) {
            return false;
        }

        // Suspending the last active admin would lock the platform out (BR-32).
        return $subject->role !== UserRole::Admin || $this->otherActiveAdminsExist($subject);
    }

    public function restore(User $user, User $subject): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    /**
     * Soft delete only. An admin never deletes their own account, and at least
     * one active admin must remain at all times (BR-32).
     */
    public function delete(User $user, User $subject): bool
    {
        if (! $this->admin($user) || ! $this->writesAllowed() || $user->is($subject)) {
            return false;
        }

        // PRD §7.8: "no user with evaluation records or an issued certificate
        // is deleted". The policy never asked, so a graded trainee — or one
        // holding a certificate a verify page vouches for — could be deleted
        // like any other account (D-84). Suspending stays available.
        if ($this->holdsAcademicRecords($subject)) {
            return false;
        }

        return $subject->role !== UserRole::Admin || $this->otherActiveAdminsExist($subject);
    }

    /**
     * Seat an EXISTING participant account in a cohort. Accounts created
     * without one — from the command line, before D-63 — had no way in from
     * the interface (D-69, D-84).
     */
    public function enroll(User $user, User $subject): bool
    {
        return $this->admin($user)
            && $this->writesAllowed()
            && $subject->role === UserRole::Participant
            && $subject->status !== UserStatus::Deleted;
    }

    /** Hard deletes are forbidden platform-wide (CONSTITUTION Art. 13 §11). */
    public function forceDelete(User $user, User $subject): bool
    {
        return false;
    }

    public function resetPassword(User $user, User $subject): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function logoutEverywhere(User $user, User $subject): bool
    {
        return ($this->admin($user) || $this->owns($user, (string) $subject->getKey()))
            && $this->writesAllowed();
    }

    /** BR-35: an admin account is never previewable, nor is one's own account. */
    public function preview(User $user, User $subject): bool
    {
        return $this->admin($user)
            && $this->writesAllowed()
            && ! $user->is($subject)
            && $subject->role !== UserRole::Admin
            && $subject->status !== UserStatus::Deleted;
    }

    public function viewAuditTrail(User $user): bool
    {
        return $this->admin($user);
    }

    /** Graded, or holding a certificate — revoked ones included: it was issued. */
    private function holdsAcademicRecords(User $subject): bool
    {
        return Evaluation::query()->where('user_id', $subject->getKey())->exists()
            || Certificate::query()->where('user_id', $subject->getKey())->exists();
    }

    private function otherActiveAdminsExist(User $subject): bool
    {
        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($subject->getKey())
            ->exists();
    }
}
