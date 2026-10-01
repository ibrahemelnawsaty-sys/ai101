<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\EmailTokenType;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Auth\Concerns\IssuesEmailTokens;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Messages\ThreadProvisioner;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates an account FOR someone and tells them it exists.
 *
 * WHY THIS SERVICE EXISTS
 * Registration for the first cohort closed, and the only path that produced a
 * usable participant ran through self-registration: the trainee registers, gets
 * an activation letter, clicks it, and `EmailVerificationController` seats them
 * in the open cohort. With that door shut there was no other way in.
 *
 * `AdminUserController::store()` looked like one and was not. It created a User
 * and a Profile, dispatched nothing, and — because `StoreUserRequest` had no
 * cohort field — left the account in NO cohort: no assignments, no sessions, no
 * card, no route to a certificate. `athar:make-user` printed "create that from
 * the admin panel", naming a screen that did not exist (it now refuses the
 * participant role, D-69). Sixty trainees had no way to reach the platform.
 *
 * ONE PLACE, ONE PATH (D-152). The single-account form and the bulk import both come
 * through `inviteByLink()`, because the interesting parts — seating, the card, the
 * conversations, the single-use link — are exactly the parts that drift when they are
 * written twice. There used to be a second path that generated a temporary password and
 * mailed it in plain text; the import used it, the form did not, and the two screens said
 * opposite things about whether a password travels by e-mail. That path is deleted.
 *
 * NO PASSWORD IS MADE, SHOWN OR MAILED. The row gets a random value that is hashed and
 * forgotten (the column cannot be null), the account stays UNVERIFIED until the link is
 * followed, and `LoginController` refuses it until then. An administrator who could read a
 * trainee's password could sign in as them without the impersonation record BR-34 requires.
 *
 * Accounts that were made by the old path keep `must_change_password` and the first-password
 * screen: that gate is still in force for them, and is not this class's to remove.
 *
 * @see PRD §4.5.1, §9.2 · BR-30, BR-34 · CONSTITUTION Art. 5, Art. 12 · D-63, D-85, D-152
 */
final class AccountInviter
{
    use IssuesEmailTokens;

    public function __construct(
        private readonly TemporaryPassword $passwords,
        private readonly AuditLogger $audit,
        private readonly CardIssuer $cards,
        private readonly ThreadProvisioner $threads,
    ) {}

    /**
     * Invite someone the platform knows almost nothing about.
     *
     * WHAT THE ADMINISTRATOR HAS is a name and an address. That is the whole
     * list they are given, and demanding a Latin name and a gender before an
     * invitation could be sent made the form unanswerable for sixty people
     * (D-85). So the account is created with exactly that, and a single-use
     * link asks the person themself for the rest.
     *
     * NO PASSWORD IS GENERATED AND NONE IS MAILED. The row is given a random
     * value that is hashed and immediately forgotten — a column that cannot be
     * null and must not be guessable — and the account stays UNVERIFIED, so
     * `LoginController` refuses it until the link is followed. The only way in
     * is the invitation.
     *
     * The seat, the card and the conversations are the invitation's, not the
     * acceptance's: the trainee appears on the roster the moment they are
     * invited, and `InvitationController` has nothing to build.
     *
     * @param  array<string, mixed>  $profileColumns  already mapped to `profiles` column names
     */
    public function inviteByLink(
        string $email,
        UserRole $role,
        array $profileColumns,
        ?Cohort $cohort,
    ): User {
        $now = Clock::now();

        $user = DB::transaction(function () use ($email, $role, $profileColumns, $cohort, $now): User {
            /** @var User $user */
            $user = User::query()->create([
                'email' => $email,
                // Hashed by the model's `hashed` cast and never read back: the
                // holder replaces it on the invitation screen. It exists only
                // because the column is NOT NULL, and it is random so that a
                // never-accepted invitation is not an account with a known
                // password sitting in the table.
                'password_hash' => $this->passwords->generate(),
                'role' => $role->value,
                'status' => UserStatus::Active->value,
                // NOT verified: the link is what proves the address, and until
                // it is followed the account cannot sign in at all.
                'email_verified_at' => null,
                'locale' => (string) config('athar.locales.default', 'ar'),
                'failed_login_count' => 0,
                'locked_until' => null,
                // There is no temporary password to force a change of; the
                // invitation screen is where the first password is chosen.
                'must_change_password' => false,
                'temp_password_expires_at' => null,
                'invited_at' => $now,
            ]);

            Profile::query()->create(array_merge($profileColumns, ['user_id' => $user->getKey()]));

            if ($cohort !== null && $role === UserRole::Participant) {
                $this->seat($user, $cohort, $now);
                $this->cards->issueFor($user);
            }

            $this->audit->log('user.invited', $user, null, [
                'email' => $email,
                'role' => $role->value,
                'cohort_id' => $cohort?->getKey(),
                'by' => 'link',
            ]);

            return $user;
        });

        // Outside the transaction: the token row is committed with the account,
        // and the letter leaves through the queue either way.
        $this->issueToken($user, EmailTokenType::Invite);

        return $user;
    }

    /**
     * Send the invitation link again — for someone who lost the letter, or
     * whose link lapsed before they opened it. Issuing retires the earlier one,
     * so there is never a second way in.
     */
    public function resendLink(User $user): void
    {
        $this->issueToken($user, EmailTokenType::Invite);
    }

    /**
     * Seat an account that already exists — the administrator's "add to a
     * cohort" on the user page (D-84). The same seat, card and conversations
     * an invitation gives, in one transaction, and no letter: the person has
     * their credentials already.
     *
     * @return bool false when the account was already in that cohort
     */
    public function enrollExisting(User $user, Cohort $cohort): bool
    {
        return DB::transaction(function () use ($user, $cohort): bool {
            // The answer comes from seat(), read AFTER the cohort lock. A read
            // here, before it, fixed MySQL's snapshot: a double-clicked second
            // request then could not see the first one's row, inserted again,
            // and met the unique key as a 500.
            if (! $this->seat($user, $cohort, Clock::now())) {
                return false;
            }

            $this->cards->issueFor($user);

            $this->audit->log('enrollment.seated', $user, null, [
                'cohort_id' => $cohort->getKey(),
            ]);

            return true;
        });
    }

    /**
     * Seat the account in its cohort, exactly as the verification path does.
     *
     * The `exists` check is not decoration: this service is reachable twice for
     * the same person if an administrator retries a timed-out import, and
     * `enrollments` has no unique index to catch it.
     *
     * THE LOCK IS TAKEN HERE, FIRST. It used to be taken by the form request,
     * in its own SELECT before this transaction began — so it was released the
     * moment it was taken, and two invitations into one cohort each read the
     * old count and wrote old+1 (D-69). And it must come before the enrolment
     * insert: `enrollments.cohort_id` is a foreign key, so the insert takes a
     * shared lock on the cohort row, and the counter update after it needs an
     * exclusive one — two concurrent invites would deadlock. increment() after
     * the insert does not avoid that. firstOrFail() rolls the new account back
     * if its cohort has vanished: D-63 exists to end accounts in no cohort.
     *
     * Whether an invitation may seat a trainee past capacity is an open
     * question (D-69); today it may, as it always could.
     *
     * @return bool true when this call created the enrolment
     */
    private function seat(User $user, Cohort $cohort, CarbonImmutable $at): bool
    {
        /** @var Cohort $locked */
        $locked = Cohort::query()->whereKey($cohort->getKey())->lockForUpdate()->firstOrFail();

        $already = Enrollment::query()
            ->where('cohort_id', $locked->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if ($already) {
            return false;
        }

        Enrollment::query()->create([
            'cohort_id' => $locked->getKey(),
            'user_id' => $user->getKey(),
            'role_in_cohort' => EnrollmentRole::Participant->value,
            'enrolled_at' => $at,
            'status' => EnrollmentStatus::Active->value,
        ]);

        $locked->setAttribute('seats_taken', (int) $locked->getAttribute('seats_taken') + 1);
        $locked->save();

        // Their three conversations (PRD §9.13, D-82).
        $this->threads->seatParticipant($user, $locked);

        return true;
    }
}
