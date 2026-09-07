<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Permissions\ImpersonationService;
use Illuminate\Http\RedirectResponse;

/**
 * Account preview — the strongest permission on the platform (PRD §4.5).
 *
 * Everything that makes it safe lives below this controller, on purpose:
 *   BR-33 — the preview is read-only, enforced by middleware and by every
 *           policy, so a write refused here is refused when the endpoint is
 *           called directly too.
 *   BR-34 — nothing about the previewed account is touched: no last-login
 *           stamp, no read receipts, no download counters.
 *   BR-35 — an administrator may never preview another administrator.
 * The session ends by itself after thirty minutes, and both its start and its
 * end are written to the trail before they take effect.
 *
 * @see BR-27, BR-33, BR-34, BR-35 · PRD §4.5 · CONSTITUTION Art. 23
 */
final class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    /**
     * Begin previewing one account. The policy answers first — an
     * administrator, another administrator, a deleted account are all refused —
     * and the service checks the very same things again before it starts.
     */
    public function start(User $user): RedirectResponse
    {
        $this->authorize('preview', $user);

        $this->impersonation->start($this->currentAdmin(), $user);

        return redirect()
            ->route('dashboard')
            ->with('status', __('admin.impersonation.started'));
    }

    /**
     * End the preview and return the administrator to their own session,
     * without a new sign-in (PRD §4.5.2). This route stays reachable while a
     * preview is running — it is one of the two escapes the read-only guard
     * lets through.
     */
    public function stop(): RedirectResponse
    {
        $admin = $this->impersonation->stop();

        if ($admin === null) {
            return redirect()->route('login');
        }

        return redirect()
            ->route('admin.users.index')
            ->with('status', __('admin.impersonation.ended'));
    }

    private function currentAdmin(): User
    {
        /** @var User $admin */
        $admin = request()->user();

        return $admin;
    }
}
