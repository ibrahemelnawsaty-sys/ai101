<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNotificationDefaultsRequest;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Presenters\Admin\GeneralSettings;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * General settings (PRD §9.18).
 *
 * The identity values — programme name, domain, contact address, WhatsApp
 * number — are read from config/athar.php, which reads them from the
 * environment (BR-36, PROJECT-CONTRACT §1). They are shown here so the centre
 * can see what is deployed, and they are not editable from a web form: a change
 * to them is a deployment, not a click.
 *
 * The display timezone is fixed at Asia/Riyadh by CONSTITUTION Art. 11 and is
 * likewise shown rather than offered.
 *
 * @see BR-36 · PRD §9.18 · CONSTITUTION Art. 11, Art. 6 · D-117
 */
final class SettingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(): View
    {
        $this->authorize('console.settings');

        return view('admin.settings', [
            'contextLabel' => null,
            'settings' => GeneralSettings::fromConfig(),
            'locale' => (string) config('athar.locales.default', 'ar'),
            'errorState' => null,
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $this->audit->record(
            action: 'settings.updated',
            entityType: 'settings',
            entityId: null,
            before: null,
            after: $request->validated(),
        );

        return back()->with('status', __('admin.settings.saved'));
    }

    /**
     * Platform-wide notification defaults (PRD §9.16.1).
     *
     * There is no table for platform defaults in PROJECT-CONTRACT §4 — the
     * per-user table `notification_preferences` is the only one — so the choice
     * is recorded in the append-only trail rather than written to a table this
     * slice would have had to invent (Art. 4). The gap is raised with this
     * slice for a product decision.
     */
    public function notifications(UpdateNotificationDefaultsRequest $request): RedirectResponse
    {
        $this->audit->record(
            action: 'settings.notifications_updated',
            entityType: 'settings',
            entityId: null,
            before: null,
            after: $request->validated(),
        );

        return back()->with('status', __('admin.settings.saved'));
    }
}
