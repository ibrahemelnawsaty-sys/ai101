<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\InvitationLetter;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\Program;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Messages\ThreadProvisioner;
use App\Services\Time\Clock;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
 * ONE PLACE, TWO CALLERS. The single-account form and the bulk import both come
 * through here, because the interesting parts — seating, the temporary
 * password, its expiry, the letter — are exactly the parts that drift when they
 * are written twice.
 *
 * THE PLAINTEXT PASSWORD NEVER LEAVES THIS METHOD. It is hashed into the row by
 * the model's `hashed` cast, handed to one encrypted queued letter, and dropped.
 * It is never returned to the caller, never written to `audit_logs`, never
 * flashed, and never shown to the administrator: an administrator who can read
 * a trainee's password can sign in as that trainee without leaving the
 * impersonation record BR-34 requires.
 *
 * WHY THE ACCOUNT IS CREATED ALREADY VERIFIED
 * `LoginController` refuses an account whose `email_verified_at` is null, and
 * `resendVerification()` refuses one where it is set — so an invited account
 * that arrives unverified can neither sign in nor be helped. It is verified on
 * creation because the invitation IS the proof: the only way to know the
 * password is to have received the letter sent to that address.
 *
 * @see PRD §4.5.1, §9.2 · BR-30, BR-34 · CONSTITUTION Art. 5, Art. 12 · D-63
 */
final class AccountInviter
{
    public function __construct(
        private readonly TemporaryPassword $passwords,
        private readonly AuditLogger $audit,
        private readonly CardIssuer $cards,
        private readonly ThreadProvisioner $threads,
    ) {}

    /**
     * Create one account, seat it, and send its invitation.
     *
     * Returns the User. It deliberately does NOT return the password.
     *
     * @param  array<string, mixed>  $profileColumns  already mapped to `profiles` column names
     */
    public function invite(
        string $email,
        UserRole $role,
        array $profileColumns,
        ?Cohort $cohort,
    ): User {
        $password = $this->passwords->generate();
        $now = Clock::now();
        $expiresAt = $now->addDays($this->expiryDays());

        $user = DB::transaction(function () use ($email, $role, $profileColumns, $cohort, $password, $now, $expiresAt): User {
            /** @var User $user */
            $user = User::query()->create([
                'email' => $email,
                // The `hashed` cast on the model turns this into a bcrypt hash
                // on assignment; the plaintext is never written to the row.
                'password_hash' => $password,
                'role' => $role->value,
                'status' => UserStatus::Active->value,
                'email_verified_at' => $now,
                'locale' => (string) config('athar.locales.default', 'ar'),
                'failed_login_count' => 0,
                'locked_until' => null,
                'must_change_password' => true,
                'temp_password_expires_at' => $expiresAt,
                'invited_at' => $now,
            ]);

            Profile::query()->create(array_merge($profileColumns, ['user_id' => $user->getKey()]));

            if ($cohort !== null && $role === UserRole::Participant) {
                $this->seat($user, $cohort, $now);

                // The card is issued HERE, not through an event.
                //
                // `CardIssuer` is reached by two listeners, on `AccountVerified`
                // and on `EnrollmentApproved`, and an invited account triggers
                // neither: it is verified by this method rather than by clicking
                // a link, and it is seated rather than approved. So every
                // trainee in the cohort would have arrived to a card screen
                // whose own empty state (`card.empty_body` in lang/ar) promises
                // the card is created automatically the moment the account is
                // verified and enrolled -- a promise nothing kept.
                //
                // Not `EnrollmentApproved::dispatch()`, which would ALSO send
                // the approval letter beside the invitation: two letters, thirty
                // seconds apart, telling the same person the same thing.
                //
                // `issueFor` is idempotent — `(user_id, cohort_id)` is unique
                // and it returns the existing row — so a retried import cannot
                // mint a second card.
                $this->cards->issueFor($user);
            }

            // The audit records that an invitation happened and to which
            // address. It records NOTHING about the credential — not its
            // length, not a prefix, not a hash.
            $this->audit->log('user.invited', $user, null, [
                'email' => $email,
                'role' => $role->value,
                'cohort_id' => $cohort?->getKey(),
            ]);

            return $user;
        });

        $this->send($user, $password, $cohort, $expiresAt);

        return $user;
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

    /**
     * The letter, queued once and encrypted.
     *
     * A delivery that fails must not undo the account: the row is already
     * committed, the administrator can re-send, and rolling back an existing
     * trainee because a mail server was slow would be the worse outcome. The
     * Mailable is queued, so what is caught here is a failure to WRITE the job,
     * not a failure to deliver it — delivery failures land in `failed_jobs`.
     */
    private function send(User $user, string $password, ?Cohort $cohort, CarbonImmutable $expiresAt): void
    {
        Mail::to($user->getAttribute('email'))->send(new InvitationLetter(
            displayName: $this->displayName($user),
            email: (string) $user->getAttribute('email'),
            password: $password,
            loginUrl: route('login'),
            expiresOn: Dates::longDate($expiresAt),
            programName: $this->programName($cohort),
            cohortName: $cohort === null ? '' : (string) $cohort->getAttribute('name'),
        ));
    }

    private function displayName(User $user): string
    {
        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : $user->profile()->first();

        if (! $profile instanceof Profile) {
            return '';
        }

        return trim((string) $profile->getAttribute('first_name_ar'));
    }

    private function programName(?Cohort $cohort): string
    {
        $fallback = (string) config('athar.program_name');

        if ($cohort === null) {
            return $fallback;
        }

        $program = $cohort->relationLoaded('program')
            ? $cohort->getRelation('program')
            : $cohort->program()->first();

        return $program instanceof Program
            ? (string) $program->getAttribute('name_ar')
            : $fallback;
    }

    /** BR-36: the window is configuration, never a literal in this file. */
    private function expiryDays(): int
    {
        $days = (int) config('athar.invitations.temp_password_days', 7);

        return $days > 0 ? $days : 7;
    }
}
