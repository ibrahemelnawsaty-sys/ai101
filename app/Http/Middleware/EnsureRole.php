<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\LogsDenials;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\RoleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow-list role gate. Registered as the `role` alias.
 *
 * Usage: `role:participant` or `role:trainer,admin`. A user passes when the
 * requested role is one of their effective roles — the global role on the
 * account, or a role carried by an active enrollment (PRD §4.4).
 *
 * Fails closed: no session, a non-active account or an unknown role all end in
 * 403, and every refusal is written to the audit log with the caller's IP.
 *
 * @see BR-22, BR-23, BR-28 · PRD §4.2, §4.3 · CONSTITUTION Art. 5, Art. 22
 */
final class EnsureRole
{
    use LogsDenials;

    public function __construct(
        private readonly RoleResolver $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next, string ...$allowed): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if (! $this->roles->isActive($user)) {
            $this->logDenial($this->audit, $request, 'route.inactive_account', 'user', (string) $user->getKey());

            abort(Response::HTTP_FORBIDDEN);
        }

        if ($allowed === []) {
            // A `role` middleware with no argument would silently allow anyone.
            abort(Response::HTTP_FORBIDDEN);
        }

        if (! $this->roles->hasAnyRole($user, array_values($allowed))) {
            $this->logDenial($this->audit, $request, 'route.role_denied', 'user', (string) $user->getKey(), [
                'required_roles' => array_values($allowed),
            ]);

            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
