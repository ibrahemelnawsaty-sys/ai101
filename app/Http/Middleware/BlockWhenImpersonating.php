<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\LogsDenials;
use App\Services\Audit\AuditLogger;
use App\Support\ImpersonationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered as the `not.impersonating` alias.
 *
 * Rejects every request while an account preview is running, whatever its verb.
 * It sits on the endpoints that act *as the previewed user* — check-in,
 * check-out, submitting work, sending a message, editing a profile — so that
 * calling them directly, outside the UI, still fails (BR-33).
 *
 * @see BR-33, BR-34 · PRD §4.5.2 · CONSTITUTION Art. 23
 */
final class BlockWhenImpersonating
{
    use LogsDenials;

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (ImpersonationContext::isActive()) {
            $this->logDenial($this->audit, $request, 'impersonation.write_attempt', 'user', ImpersonationContext::targetId());

            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
