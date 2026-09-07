<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\LogsDenials;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\RoleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in account that is no longer active is turned away on its very next
 * request, whatever that request is.
 *
 * PRD §4.4 is explicit: disabling an account invalidates its sessions
 * immediately. BR-28 says the same thing from
 * the other side: every permission is checked on the server on every request,
 * never once at sign-in. The role gate (EnsureRole) already refuses an inactive
 * account, but it only runs on routes that name a role — the dashboard, the
 * schedule and the whole read side of the participant area do not, so a
 * suspended trainee kept browsing until their session cookie expired.
 *
 * The session is destroyed rather than merely refused, because §4.4 asks for the
 * session to be invalidated, not for one answer to be withheld. The refusal is
 * written to the trail with the caller's IP first (PRD §4.3).
 *
 * @see BR-28 · PRD §4.3, §4.4 · CONSTITUTION Art. 5, Art. 22
 */
final class EnsureActiveAccount
{
    use LogsDenials;

    public function __construct(
        private readonly RoleResolver $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->roles->isActive($user)) {
            return $next($request);
        }

        $this->logDenial($this->audit, $request, 'account.inactive', 'user', (string) $user->getKey());

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('login')
            ->withErrors(['email' => __('errors.inactive_account')]);
    }
}
