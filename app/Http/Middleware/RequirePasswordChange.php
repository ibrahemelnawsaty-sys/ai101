<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ImpersonationContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An invited account goes nowhere until it has replaced its temporary password.
 *
 * WHY IT IS A MIDDLEWARE AND NOT A REDIRECT AFTER SIGN-IN
 * A redirect from the login controller is a suggestion: the trainee presses
 * back, types a URL, follows a link from the invitation, or reopens a tab from
 * yesterday, and they are inside with a password that was mailed to them in
 * plain sight. The rule has to hold on EVERY request or it is not a rule
 * (Article 5). This runs on the whole `web` stack for exactly that reason.
 *
 * WHAT IT MUST NOT TRAP
 *   · The change-password screen itself, and its POST — otherwise the redirect
 *     is a loop and the account can never be freed.
 *   · Signing out. Somebody who opened the wrong invitation must be able to
 *     leave; trapping them would turn a mistake into a locked browser.
 *   · An administrator PREVIEWING this account. The flag belongs to the trainee,
 *     not to the administrator reading their screens, and forcing an
 *     administrator to set a trainee's password would hand them the credential
 *     the whole design exists to keep away from them (BR-33, BR-34).
 *
 * The exemptions are ROUTE NAMES, not paths: a path list drifts the moment a
 * prefix changes, and the drift is silent.
 *
 * @see PRD §9.2, §9.3 · BR-30, BR-33, BR-34 · CONSTITUTION Art. 5 · D-63
 */
final class RequirePasswordChange
{
    /** Reachable while the flag is set. Everything else redirects. */
    private const EXEMPT_ROUTES = [
        'password.first',
        'password.first.update',
        'logout',
        'admin.impersonation.stop',
    ];

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->getAttribute('must_change_password')) {
            return $next($request);
        }

        // The flag is the trainee's. An administrator reading their screens is
        // not the person who has to choose a password.
        if (ImpersonationContext::isActive()) {
            return $next($request);
        }

        $route = $request->route();
        $name = $route === null ? null : $route->getName();

        if ($name !== null && in_array($name, self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        // A background fetch must not be answered with a redirect to an HTML
        // page — the caller would parse a login form as data. 409 says "this
        // account is not in a state where that request means anything", and the
        // body names the screen to go to.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('auth.first_password.required'),
                'redirect' => route('password.first'),
            ], Response::HTTP_CONFLICT);
        }

        return redirect()->route('password.first');
    }
}
