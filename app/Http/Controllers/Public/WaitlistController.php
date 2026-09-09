<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Events\WaitlistJoined;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\WaitlistRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;

/**
 * The waiting list on the landing page: what a visitor does when the cohort is
 * full or registration has closed (PRD §9.1.3).
 *
 * The reply is the same sentence whichever way it went, exactly as the recovery
 * reply is: a public form must never become a way of asking the platform
 * whether an address is known (BR-30).
 *
 * There is no `waitlist` table in PROJECT-CONTRACT §4, so the interest is
 * recorded in the audit trail — an append-only record the centre can read —
 * rather than in a table this slice would have had to invent (Art. 4).
 *
 * @see BR-30, BR-31 · PRD §9.1.3 · CONSTITUTION Art. 4, Art. 8
 */
final class WaitlistController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(WaitlistRequest $request): RedirectResponse
    {
        $this->audit->record(
            action: 'waitlist.joined',
            entityType: 'waitlist',
            entityId: null,
            before: null,
            after: ['email' => (string) $request->validated('email')],
        );

        // No account, no profile, no name — an address and a promise, which
        // is all the copy claims.
        WaitlistJoined::dispatch((string) $request->validated('email'));

        return redirect()
            ->route('home')
            ->with('status', __('landing.waitlist.acknowledged'));
    }
}
