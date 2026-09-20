<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\EmailTokenType;
use App\Enums\Gender;
use App\Enums\UserStatus;
use App\Http\Controllers\Auth\Concerns\IssuesEmailTokens;
use App\Http\Controllers\Auth\Concerns\PasswordMeterCopy;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Participant\DashboardController;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\Profile;
use App\Models\User;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The invited person's one screen: finish the account, choose a password, in.
 *
 * WHY THIS EXISTS
 * An invitation used to carry a temporary password in the letter, which meant
 * the administrator had to know eleven facts about a person before that person
 * could be invited at all — including their Latin name and their gender, which
 * are not on the list an administrator is given. The invitation now carries a
 * NAME and an ADDRESS, and everything else is asked of the one who knows it,
 * behind a single-use link (D-85).
 *
 * THE ADDRESS IS FIXED. It is read from the token's account and echoed
 * read-only; `AcceptInvitationRequest` never looks at a posted `email`. The
 * address is what the administrator invited and what the letter reached — it
 * is not a field on this form in any sense.
 *
 * THE LINK IS THE PROOF OF THE ADDRESS, so accepting it verifies the account,
 * exactly as clicking an activation link does. A spent, expired or unknown link
 * opens a screen that says the link no longer works and names the way back —
 * never which of the three it was, because that would make this page a probe
 * (BR-30).
 *
 * @see PRD §4.5.1, §9.2, §9.2.1 · BR-29, BR-30 · CONSTITUTION Art. 5, Art. 24 · D-85
 */
final class InvitationController extends Controller
{
    use IssuesEmailTokens;
    use PasswordMeterCopy;

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(string $token): View
    {
        // Resolved on arrival, so a dead link opens the screen that says so
        // rather than a form that fails only after everything has been typed.
        $user = $this->findUsableToken($token, EmailTokenType::Invite)?->user;
        $profile = $user instanceof User
            ? Profile::query()->where('user_id', $user->getKey())->first()
            : null;

        return view('auth.accept-invitation', [
            'state' => 'ok',
            'token' => $token,
            'tokenValid' => $user instanceof User,
            'email' => $user instanceof User ? (string) $user->getAttribute('email') : '',
            'known' => $this->knownNames($profile),
            'genderOptions' => Options::fromEnum(Gender::class),
            'passwordCopy' => $this->passwordMeterCopy(),
        ]);
    }

    public function store(AcceptInvitationRequest $request): RedirectResponse
    {
        $record = $request->tokenRecord();
        $user = $request->invitee();

        if ($record === null || ! $user instanceof User) {
            return back()->withErrors(['token' => __('auth.invitation.invalid_body')]);
        }

        $at = Clock::now();

        DB::transaction(function () use ($record, $request, $user, $at): void {
            $record->markUsedAt($at);

            $profile = Profile::query()->where('user_id', $user->getKey())->first();

            if ($profile instanceof Profile) {
                $profile->fill($request->profileAttributes());
                $profile->save();
            } else {
                // An account with no profile row cannot happen through the
                // invitation path, which creates one. Handled rather than
                // assumed away: a missing row here would otherwise be a 500 on
                // the one screen a trainee cannot get past (art. 7).
                Profile::query()->create(array_merge(
                    $request->profileAttributes(),
                    ['user_id' => $user->getKey()],
                ));
            }

            $user->setAttribute('password_hash', (string) $request->validated('password'));
            $user->setAttribute('status', UserStatus::Active->value);
            // The link reached the address, so the address is proven — the same
            // reasoning as an activation link, and what lets them sign in.
            $user->setAttribute('email_verified_at', $at);
            // They have just chosen their own password: there is nothing
            // temporary left to force a change of.
            $user->setAttribute('must_change_password', false);
            $user->setAttribute('temp_password_expires_at', null);
            $user->setAttribute('failed_login_count', 0);
            $user->setAttribute('locked_until', null);

            // The trail records that the invitation was accepted and by which
            // account. It records nothing about the password (art. 12).
            $this->audit->log('account.invitation_accepted', $user, null, null, $user);

            $user->save();
        });

        Auth::login($user);
        $request->session()->regenerate();

        // The sign-in screen keeps its last verdict in the session; whatever it
        // said about this account before is now false.
        $request->session()->forget(['auth.account_state', 'auth.locked_until']);

        // A plain session value, not a flash: only the dashboard render that
        // shows the celebration removes it (D-75).
        $request->session()->put(DashboardController::WELCOME_KEY, true);

        return redirect()->route('dashboard');
    }

    /**
     * What the administrator already typed, offered back so the invited person
     * corrects it rather than retypes it.
     *
     * @return array<string, string>
     */
    private function knownNames(?Profile $profile): array
    {
        if (! $profile instanceof Profile) {
            return [];
        }

        return [
            'first_name_ar' => (string) $profile->getAttribute('first_name_ar'),
            'father_name_ar' => (string) $profile->getAttribute('second_name_ar'),
            'grandfather_name_ar' => (string) $profile->getAttribute('third_name_ar'),
            'family_name_ar' => (string) $profile->getAttribute('last_name_ar'),
        ];
    }
}
