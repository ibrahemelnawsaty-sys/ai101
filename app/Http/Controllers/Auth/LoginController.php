<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Signing in and signing out (PRD §9.3.1, §9.3.2).
 *
 * Two rules shape everything here:
 *   BR-30 — no message ever reveals whether an address is registered. A wrong
 *           address and a wrong password produce the identical sentence, and
 *           the failure counter is only touched for an account that exists.
 *   Five failed attempts lock the account for fifteen minutes (PRD §9.3.1);
 *           the countdown shown to the visitor is computed on the server.
 *
 * The account states the page may show — unverified, suspended, locked — are
 * only ever reached after a *correct* password, so they cannot be used to probe
 * for addresses.
 *
 * @see BR-28, BR-29, BR-30 · PRD §9.3.1, §9.3.2, §12.1 · CONSTITUTION Art. 7, Art. 24
 */
final class LoginController extends Controller
{
    /** PRD §9.3.1 — five failed attempts, then a temporary lock. */
    public const MAX_ATTEMPTS = 5;

    /** PRD §9.3.1 — the lock lasts fifteen minutes. */
    public const LOCK_MINUTES = 15;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request): View
    {
        return view('auth.login', [
            'state' => 'ok',
            'accountState' => $request->session()->get('auth.account_state'),
            'lockedForSeconds' => $request->session()->get('auth.locked_for_seconds'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('email');
        $password = (string) $request->validated('password');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        // An unknown address takes the same path and produces the same answer
        // as a wrong password: the reply must not be an oracle (BR-30).
        if ($user === null || ! Hash::check($password, $user->getAuthPassword())) {
            $this->registerFailure($user);

            return $this->rejected($request);
        }

        $now = Clock::now();

        if ($user->isLockedAt($now)) {
            return $this->lockedOut($request, $user, $now);
        }

        if ($user->getAttribute('email_verified_at') === null) {
            return $this->accountState($request, 'unverified');
        }

        if ($user->status !== UserStatus::Active) {
            return $this->accountState($request, 'suspended');
        }

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => $now,
        ])->save();

        Auth::guard('web')->login($user, $request->remembers());

        // A fresh session id on every sign-in, against session fixation
        // (PRD §12.1).
        $request->session()->regenerate();
        $request->session()->forget(['auth.account_state', 'auth.locked_for_seconds']);

        $this->audit->log('account.login', $user, null, null, $user);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Signing out really destroys the session on the server; deleting the
     * cookie alone would leave a usable row behind (PRD §9.3.2).
     *
     * Ending an account preview is a different act with a different endpoint:
     * if one is running, it is closed first so the administrator is not left
     * signed in as somebody else.
     */
    public function destroy(Request $request): RedirectResponse
    {
        ImpersonationContext::clear();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', __('auth.logout.done'));
    }

    /**
     * Count a failed attempt, and lock the account once the ceiling is reached.
     * Nothing is written for an address that does not exist — there would be no
     * row to write to, and inventing one would leak the address space.
     */
    private function registerFailure(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $attempts = (int) $user->getAttribute('failed_login_count') + 1;
        $now = Clock::now();

        $user->forceFill([
            'failed_login_count' => $attempts,
            'locked_until' => $attempts >= self::MAX_ATTEMPTS
                ? $now->addMinutes(self::LOCK_MINUTES)
                : $user->getAttribute('locked_until'),
        ])->save();

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->audit->log('account.locked', $user, null, ['attempts' => $attempts], $user);
        }
    }

    private function rejected(LoginRequest $request): RedirectResponse
    {
        return back()
            ->withInput($request->only('email', 'remember'))
            ->withErrors(['email' => __('auth.login.failed')]);
    }

    private function lockedOut(LoginRequest $request, User $user, \DateTimeInterface $now): RedirectResponse
    {
        $lockedUntil = $user->getAttribute('locked_until');
        $seconds = $lockedUntil === null
            ? 0
            : max(0, $lockedUntil->getTimestamp() - $now->getTimestamp());

        $request->session()->put('auth.account_state', 'locked');
        $request->session()->put('auth.locked_for_seconds', $seconds);

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => __('auth.login.locked_body')]);
    }

    /**
     * A state the sign-in screen may show. It is only ever reached after a
     * correct password, so remembering the address here cannot leak anything
     * an attacker did not already know (BR-30). The "resend the link" button
     * reads it, which is why that button needs no address field of its own.
     */
    private function accountState(LoginRequest $request, string $state): RedirectResponse
    {
        $request->session()->put('auth.account_state', $state);
        $request->session()->put(
            ResendVerificationRequest::PENDING_EMAIL_KEY,
            (string) $request->validated('email'),
        );
        $request->session()->forget('auth.locked_for_seconds');

        return back()->withInput($request->only('email'));
    }
}
