<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\LogsDenials;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\ImpersonationService;
use App\Support\ImpersonationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered as the `impersonation.readonly` alias and appended to the whole
 * `web` group, so it covers routes that forgot to ask for it.
 *
 * Three jobs, in order:
 *   1. Retire a preview that has reached its 30-minute ceiling (PRD §4.5.2).
 *   2. Refuse any verb other than GET/HEAD/OPTIONS while a preview is running,
 *      except the two escapes — ending the preview and logging out (BR-33).
 *   3. Publish the preview banner facts to every view, so the fixed alert bar
 *      and its always-visible exit button can render.
 *
 * This is defence in depth, not the primary control: the Policies and the data
 * layer refuse the same writes on their own.
 *
 * @see BR-33, BR-34, BR-35 · PRD §4.5.2 · CONSTITUTION Art. 23
 */
final class ImpersonationReadOnly
{
    use LogsDenials;

    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ImpersonationService $impersonation,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! ImpersonationContext::isActive()) {
            View::share('impersonation', null);

            return $next($request);
        }

        if (ImpersonationContext::hasExpired()) {
            $this->impersonation->stopIfExpired();
            View::share('impersonation', null);

            if ($request->isMethod('GET')) {
                return redirect()
                    ->route('admin.dashboard')
                    ->with('status', __('admin.impersonation.expired'));
            }

            abort(Response::HTTP_FORBIDDEN);
        }

        if (! in_array($request->getMethod(), self::SAFE_METHODS, true)
            && ! in_array((string) $request->route()?->getName(), ImpersonationContext::ESCAPE_ROUTES, true)) {
            $this->logDenial($this->audit, $request, 'impersonation.write_blocked', 'user', ImpersonationContext::targetId());

            abort(Response::HTTP_FORBIDDEN);
        }

        View::share('impersonation', [
            'target_id' => ImpersonationContext::targetId(),
            'admin_id' => ImpersonationContext::adminId(),
            'remaining_seconds' => ImpersonationContext::remainingSeconds(),
        ]);

        return $next($request);
    }
}
