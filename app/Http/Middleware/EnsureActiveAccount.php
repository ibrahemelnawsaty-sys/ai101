<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\LogsDenials;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\ImpersonationService;
use App\Services\Permissions\RoleResolver;
use App\Support\ImpersonationContext;
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
 * @see BR-28, BR-33 · PRD §4.3, §4.4 · CONSTITUTION Art. 5, Art. 22, Art. 23 · D-117
 */
final class EnsureActiveAccount
{
    use LogsDenials;

    public function __construct(
        private readonly RoleResolver $roles,
        private readonly AuditLogger $audit,
        private readonly ImpersonationService $impersonation,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->roles->isActive($user)) {
            return $next($request);
        }

        // During a preview the inactive account is the one being LOOKED AT,
        // not the one looking. Signing the session out left the preview row
        // open with no end in the trail (art. 23) and punished the system
        // administrator for someone else's status. The preview ends through
        // the service instead, and they return to their own account (D-117).
        if (ImpersonationContext::isActive()) {
            $this->logDenial($this->audit, $request, 'impersonation.target_inactive', 'user', (string) $user->getKey());

            if ($this->impersonation->stop() !== null) {
                return redirect()
                    ->route('admin.users.index')
                    ->with('warning', __('admin.impersonation.target_inactive'));
            }
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
