<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\LogsDenials;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\ImpersonationService;
use App\Support\ImpersonationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered as the `impersonation.readonly` alias and appended to the whole
 * `web` group, so it covers routes that forgot to ask for it.
 *
 * Four jobs, in order:
 *   1. Retire a preview that has reached its 30-minute ceiling (PRD §4.5.2).
 *   2. End a preview whose previewer is no longer entitled to it — suspended,
 *      deleted or no longer a system administrator (BR-28, D-117).
 *   3. Refuse any verb other than GET/HEAD/OPTIONS while a preview is running,
 *      except the two escapes — ending the preview and logging out (BR-33).
 *   4. Publish the preview banner facts to every view, so the fixed alert bar
 *      and its always-visible exit button can render.
 *
 * This is defence in depth, not the primary control: the Policies and the data
 * layer refuse the same writes on their own.
 *
 * @see BR-28, BR-33, BR-34, BR-35 · PRD §4.5.2 · CONSTITUTION Art. 23 · D-117
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

    public function handle(Request $request, \Closure $next): Response
    {
        if (! ImpersonationContext::isActive()) {
            View::share('impersonation', null);

            return $next($request);
        }

        $route = (string) $request->route()?->getName();

        if (ImpersonationContext::hasExpired()) {
            $this->impersonation->stopIfExpired();
            View::share('impersonation', null);

            // Signing out is still what was asked for; the session it now
            // signs out is the restored one (D-117 — it used to answer 403).
            if ($route === 'logout') {
                return $next($request);
            }

            // Back to the accounts list, as ending a preview by hand does: the
            // one who previewed is a system administrator (D-117), and the
            // supervisor's console home would refuse them. "End the preview"
            // lands there too: the preview it asked to end has just ended.
            if ($request->isMethod('GET') || $route === 'admin.impersonation.stop') {
                return redirect()
                    ->route('admin.users.index')
                    ->with('status', __('admin.impersonation.expired'));
            }

            abort(Response::HTTP_FORBIDDEN);
        }

        // BR-28 — the right to preview is re-checked on every request, not
        // only when the preview began: a system administrator suspended,
        // deleted or given another role mid-preview stops here (D-117). The
        // preview is ended through the service, so its end is in the trail;
        // an inactive previewer is signed out rather than restored.
        if (! $this->impersonation->previewerStillEntitled()) {
            $this->logDenial($this->audit, $request, 'impersonation.previewer_revoked', 'user', ImpersonationContext::adminId());

            $restored = $this->impersonation->stop();
            View::share('impersonation', null);

            // The two exits still do what was asked, as after the ceiling.
            if ($route === 'logout') {
                return $next($request);
            }

            if ($request->isMethod('GET') || $route === 'admin.impersonation.stop') {
                return redirect()->route($restored === null ? 'login' : 'dashboard');
            }

            abort(Response::HTTP_FORBIDDEN);
        }

        if (! in_array($request->getMethod(), self::SAFE_METHODS, true)
            && ! in_array($route, ImpersonationContext::ESCAPE_ROUTES, true)) {
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
