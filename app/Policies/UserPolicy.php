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
 * D-117 gave all of it to the system administrator: the directory, every change
 * to an account and the preview. The general supervisor keeps exactly one
 * ability here — seating an existing trainee in a cohort, which is a decision
 * about the programme and is taken from the cohorts screen.
 *
 * Three rules here are absolute and are re-stated in the service layer:
 * nobody acts on their own account through these screens; the platform never
 * drops below one active holder of either administrative role (BR-32, asked of
 * RoleResolver::isLastActiveHolder()); and a system administrator never
 * previews another system administrator (BR-35).
 *
 * @see BR-22, BR-32, BR-33, BR-35 · PRD §4.2, §4.3, §4.5 · CONSTITUTION Art. 22 · D-117
 */
final class UserPolicy
{
    use InteractsWithScope;

    /** The account directory — the system administrator's alone (D-117). */
    public function viewAny(User $user): bool
    {
        return $this->systemAdmin($user);
    }

    /**
     * Own account always; the system administrator sees everyone; a trainer
     * sees the profile of a participant who shares one of their cohorts, and
     * nobody else (BR-23). The supervisor does not browse accounts (D-117).
     */
    public function view(User $user, User $subject): bool
    {
        if ($this->owns($user, (string) $subject->getKey()) || $this->systemAdmin($user)) {
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
        return $this->systemAdmin($user) && $this->writesAllowed();
    }

    public function update(User $user, User $subject): bool
    {
        return $this->systemAdmin($user) && $this->writesAllowed() && $subject->status !== UserStatus::Deleted;
    }

    /** Own profile edits. Never available while previewing (BR-33). */
    public function updateOwnProfile(User $user, User $subject): bool
    {
        return $this->owns($user, (string) $subject->getKey()) && $this->writesAllowed();
    }

    /** Only the system administrator changes a role, and never their own (PRD §4.3, D-117). */
    public function changeRole(User $user, User $subject): bool
    {
        if (! $this->systemAdmin($user) || ! $this->writesAllowed() || $user->is($subject)) {
            return false;
        }

        // BR-32: demoting the last active holder of an administrative role
        // empties the platform of it just as surely as suspending or deleting
        // them would.
        return ! $this->roles->isLastActiveHolder($subject);
    }

    public function suspend(User $user, User $subject): bool
    {
        if (! $this->systemAdmin($user) || ! $this->writesAllowed() || $user->is($subject)) {
            return false;
        }

        // Suspending the last active supervisor or system administrator would
        // lock the platform out of that role (BR-32).
        return ! $this->roles->isLastActiveHolder($subject);
    }

    public function restore(User $user, User $subject): bool
    {
        return $this->systemAdmin($user) && $this->writesAllowed();
    }

    /**
     * Soft delete only. Nobody deletes their own account, and at least one
     * active holder of each administrative role remains at all times (BR-32).
     */
    public function delete(User $user, User $subject): bool
    {
        if (! $this->systemAdmin($user) || ! $this->writesAllowed() || $user->is($subject)) {
            return false;
        }

        // PRD §7.8: "no user with evaluation records or an issued certificate
        // is deleted". The policy never asked, so a graded trainee — or one
        // holding a certificate a verify page vouches for — could be deleted
        // like any other account (D-84). Suspending stays available.
        if ($this->holdsAcademicRecords($subject)) {
            return false;
        }

        return ! $this->roles->isLastActiveHolder($subject);
    }

    /**
     * Seat an EXISTING participant account in a cohort — the supervisor's, from
     * the cohorts screen (D-84, D-117). Accounts created without one had no way
     * in from the interface (D-69).
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
        return $this->systemAdmin($user) && $this->writesAllowed();
    }

    public function logoutEverywhere(User $user, User $subject): bool
    {
        return ($this->systemAdmin($user) || $this->owns($user, (string) $subject->getKey()))
            && $this->writesAllowed();
    }

    /**
     * The account preview — the system administrator's alone (D-117). Any
     * account may be previewed, a supervisor's included, except another system
     * administrator's (BR-35), one's own, and a deleted one.
     */
    public function preview(User $user, User $subject): bool
    {
        return $this->systemAdmin($user)
            && $this->writesAllowed()
            && ! $user->is($subject)
            && $subject->role !== UserRole::SystemAdmin
            && $subject->status !== UserStatus::Deleted;
    }

    /**
     * The whole account list as a spreadsheet. Nobody, since D-117: the
     * supervisor does not browse accounts, and the owner chose that the system
     * administrator reads them on screen without taking the personal data of
     * every account off the platform. One line to change if that is revisited.
     */
    public function export(User $user): bool
    {
        return false;
    }

    /**
     * The trail of what was done to one account. It carries IP addresses, so
     * it stays with the supervisor's audit screen (AuditLogPolicy) and is not
     * part of the system administrator's account page (D-117).
     */
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
}
