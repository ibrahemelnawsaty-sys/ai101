<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Events\PasswordChanged;
use App\Http\Controllers\Auth\Concerns\InvalidatesOtherSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirstPasswordRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The one screen an invited account sees before anything else.
 *
 * `RequirePasswordChange` sends every request here while
 * `users.must_change_password` is true, so this controller is the only way out.
 * It clears exactly what `AccountInviter::invite()` set — the flag and the
 * expiry — and nothing else: two files, one fact, and the mirror written on
 * purpose so they cannot drift.
 *
 * THE SECURITY NOTICE IS SUPPRESSED HERE, DELIBERATELY.
 * `PasswordChanged` is still dispatched, because the password did change and
 * the trail should say so. But `SendPasswordChangedNotice` mails
 * «your password was changed at …» unconditionally, and on this screen that
 * letter would land minutes after the invitation — the queue is drained by a
 * per-minute cron — warning a trainee about a change they made themselves,
 * thirty seconds earlier, at the platform's own insistence. Sixty invitations
 * would mean a hundred and twenty letters through one shared mailbox on day
 * one. The listener now reads `$event->source`, which is why that field exists.
 *
 * THE EXPIRED CASE HAS NO LOOP.
 * A lapsed temporary password is refused at sign-in by `LoginController`, so
 * the holder stays a guest and password recovery — which lives behind the
 * `guest` middleware — is reachable. If one lapses while this very screen is
 * open, `update()` signs the trainee out and sends them there rather than
 * refusing in place: `password.request` is guest-only, so a signed-in user who
 * clicked it would be bounced to the dashboard and then straight back here,
 * forever.
 *
 * @see BR-29, BR-30 · PRD §9.3.3 · CONSTITUTION Art. 5, Art. 17 · D-63
 */
final class FirstPasswordController extends Controller
{
    use InvalidatesOtherSessions;

    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('auth.first-password', [
            'email' => (string) $user->getAttribute('email'),
            'expiresAt' => $user->getAttribute('temp_password_expires_at'),
            'hasExpired' => $this->hasExpired($user),
        ]);
    }

    public function update(FirstPasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->hasExpired($user)) {
            return $this->signOutToRecovery($request);
        }

        $at = Clock::now();

        DB::transaction(function () use ($user, $request): void {
            $user->setAttribute('password_hash', (string) $request->validated('password'));
            // The mirror of AccountInviter::invite(): the flag it set and the
            // expiry it stamped both stop meaning anything now.
            $user->setAttribute('must_change_password', false);
            $user->setAttribute('temp_password_expires_at', null);

            $this->audit->log('account.first_password_set', $user, null, null, $user);

            $user->save();
        });

        // BR-29, and it needs the trait — not `session()->regenerate()`, which
        // rotates THIS browser's id and deletes nothing.
        //
        // It matters more here than anywhere else in the platform. This letter
        // carried the address and the password in plaintext to an inbox that in
        // practice gets forwarded. Anyone else who signed in with it is trapped
        // on this very screen by `RequirePasswordChange` and can see nothing —
        // until the real trainee sets their password. At that instant
        // `must_change_password` flips to false FOR THE ACCOUNT, and the
        // intruder's still-live row in `user_sessions` is promoted from
        // "trapped on one screen" to a full trainee session.
        //
        // The mundane version needs no intruder at all: the trainee opens the
        // invitation on a phone and again on a laptop, sets the password on the
        // laptop, and the phone stays signed in on a credential that no longer
        // exists.
        //
        // The trait deletes the other rows and nulls `remember_token`, so a
        // "remember me" cookie taken at that sign-in dies with them.
        $this->invalidateOtherSessions($request, $user);

        PasswordChanged::dispatch($user, $at, 'invitation');

        // The celebration is a one-shot flash rather than a query parameter or
        // a column: a parameter can be pasted into a browser by anybody, and a
        // column would have to be reset for a second cohort. A flash is spent
        // by the render that reads it and survives no refresh, which is exactly
        // the lifetime this belongs to.
        return redirect()
            ->route('dashboard')
            ->with('athar.welcome', true);
    }

    /** A temporary password that has lapsed is not a password any more. */
    private function hasExpired(User $user): bool
    {
        $expiresAt = $user->getAttribute('temp_password_expires_at');

        return $expiresAt !== null && Clock::now()->greaterThan($expiresAt);
    }

    /**
     * Sign out, then send them to recovery.
     *
     * Recovery is behind `guest`. Redirecting a signed-in user there would hand
     * them to `RedirectIfAuthenticated`, which sends them to the dashboard,
     * where `RequirePasswordChange` sends them back here: a loop with no exit
     * and no message. Signing out first is what makes the link work at all.
     */
    private function signOutToRecovery(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('password.request')
            ->with('status', __('auth.first_password.expired_recover'));
    }
}
