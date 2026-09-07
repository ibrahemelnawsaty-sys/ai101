<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\CohortStatus;
use App\Enums\EmailTokenType;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserStatus;
use App\Events\AccountVerified;
use App\Http\Controllers\Auth\Concerns\IssuesEmailTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Following the activation link, and asking for a new one (PRD §9.2.3).
 *
 * The link works once and dies after 24 hours; both facts are decided against
 * the server clock (BR-07). Following a good link activates the account, seats
 * it in the open cohort — under a row lock, so the last seat cannot be sold
 * twice — signs the visitor in and announces the fact so the digital card and
 * the journey can be built by the parts that own them (BR-20, BR-21).
 *
 * The resend endpoint answers the same sentence whatever the truth is: whether
 * an address exists, is already verified, or was never registered is not
 * something a public form may reveal (BR-30).
 *
 * @see BR-07, BR-20, BR-21, BR-30 · PRD §9.2.3 · CONSTITUTION Art. 7, Art. 24
 */
final class EmailVerificationController extends Controller
{
    use IssuesEmailTokens;

    public function __construct(private readonly AuditLogger $audit) {}

    public function verify(string $token): RedirectResponse
    {
        $record = $this->findUsableToken($token, EmailTokenType::Verify);

        if ($record === null) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('auth.verify.link_invalid')]);
        }

        $user = $record->user;

        if (! $user instanceof User) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('auth.verify.link_invalid')]);
        }

        $at = Clock::now();

        DB::transaction(function () use ($record, $user, $at): void {
            $record->markUsedAt($at);

            $user->setAttribute('email_verified_at', $at);
            $user->setAttribute('status', UserStatus::Active->value);

            $this->audit->log(
                action: 'account.verified',
                entity: $user,
                before: ['status' => UserStatus::Pending->value],
                after: ['status' => UserStatus::Active->value],
                actor: $user,
            );

            $user->save();

            $this->seatInOpenCohort($user, $at);
        });

        Auth::guard('web')->login($user, false);
        request()->session()->regenerate();

        AccountVerified::dispatch($user);

        return redirect()
            ->route('dashboard')
            ->with('status', __('auth.verify.welcome'));
    }

    /**
     * Ask for another activation link. Rate limited by `throttle:password` and
     * deliberately incapable of confirming anything about the address (BR-30).
     */
    public function send(ResendVerificationRequest $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = User::query()
            ->where('email', (string) $request->validated('email'))
            ->whereNull('email_verified_at')
            ->first();

        if ($user !== null) {
            $this->issueToken($user, EmailTokenType::Verify);
        }

        return back()->with('status', __('auth.verify.resent'));
    }

    /**
     * Seat a freshly activated account in the cohort that is open, if one is.
     *
     * The cohort row is locked for the duration so two people activating at the
     * same instant cannot both take the final seat; a full cohort simply leaves
     * the account without an enrolment, which every screen renders as its empty
     * state rather than as an error (Art. 17).
     */
    private function seatInOpenCohort(User $user, \DateTimeInterface $at): void
    {
        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()
            ->where('status', CohortStatus::Open->value)
            ->orderBy('start_date')
            ->lockForUpdate()
            ->first();

        if ($cohort === null || $cohort->seatsRemaining() <= 0) {
            return;
        }

        $exists = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if ($exists) {
            return;
        }

        Enrollment::query()->create([
            'cohort_id' => $cohort->getKey(),
            'user_id' => $user->getKey(),
            'role_in_cohort' => EnrollmentRole::Participant->value,
            'enrolled_at' => $at,
            'status' => EnrollmentStatus::Active->value,
        ]);

        $cohort->setAttribute('seats_taken', (int) $cohort->seats_taken + 1);
        $cohort->save();
    }
}
