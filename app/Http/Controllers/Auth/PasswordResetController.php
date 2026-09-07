<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\EmailTokenType;
use App\Events\PasswordChanged;
use App\Http\Controllers\Auth\Concerns\InvalidatesOtherSessions;
use App\Http\Controllers\Auth\Concerns\IssuesEmailTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Recovering a forgotten password (PRD §9.3.3).
 *
 * The reply to a recovery request is one fixed sentence, sent whether or not
 * the address is known: the page must not become a way of enumerating accounts
 * (BR-30). The link is single use, lives thirty minutes, and setting the new
 * password ends every other session of that account for real (BR-29).
 *
 * @see BR-29, BR-30 · PRD §9.3.3 · CONSTITUTION Art. 24
 */
final class PasswordResetController extends Controller
{
    use InvalidatesOtherSessions;
    use IssuesEmailTokens;

    public function __construct(private readonly AuditLogger $audit) {}

    public function request(): View
    {
        return view('auth.forgot-password', ['state' => 'ok']);
    }

    public function email(ForgotPasswordRequest $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = User::query()
            ->where('email', (string) $request->validated('email'))
            ->first();

        if ($user !== null) {
            $this->issueToken($user, EmailTokenType::Reset);
        }

        // Identical answer in both branches, deliberately (BR-30).
        return back()->with('status', __('passwords.sent'));
    }

    public function reset(string $token): View
    {
        return view('auth.reset-password', [
            'state' => 'ok',
            'token' => $token,
        ]);
    }

    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $record = $this->findUsableToken((string) $request->validated('token'), EmailTokenType::Reset);

        if ($record === null) {
            return back()->withErrors(['token' => __('passwords.token_invalid')]);
        }

        $user = $record->user;

        if (! $user instanceof User) {
            return back()->withErrors(['token' => __('passwords.token_invalid')]);
        }

        $at = Clock::now();

        DB::transaction(function () use ($record, $user, $request, $at): void {
            $record->markUsedAt($at);

            $user->setAttribute('password_hash', (string) $request->validated('password'));
            $user->setAttribute('failed_login_count', 0);
            $user->setAttribute('locked_until', null);

            $this->audit->log('account.password_reset', $user, null, null, $user);

            $user->save();
        });

        // BR-29 — the old sessions die with the old password.
        $this->invalidateOtherSessions($request, $user);

        PasswordChanged::dispatch($user, $at, 'reset');

        return redirect()
            ->route('login')
            ->with('status', __('passwords.reset'));
    }
}
