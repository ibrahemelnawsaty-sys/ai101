<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\LandingSetting;
use App\Presenters\Admin\GeneralSettings;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

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
 * @see BR-36 · PRD §9.18 · CONSTITUTION Art. 11, Art. 6
 */
final class SettingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(): View
    {
        $this->authorize('update', new LandingSetting);

        return view('admin.settings', [
            'contextLabel' => null,
            'settings' => GeneralSettings::fromConfig(),
            'locale' => (string) config('athar.locales.default', 'ar'),
            'template' => null,
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
    public function notifications(UpdateSettingsRequest $request): RedirectResponse
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

    /**
     * One e-mail template, shown for review.
     *
     * Template bodies live in lang/ar/emails.php like every other Arabic string
     * on the platform (Art. 15), so this screen shows the text that will be
     * sent; editing it is a translation change, not a form submission.
     */
    public function template(string $template): View
    {
        $this->authorize('update', new LandingSetting);

        $body = __('emails.'.$template);

        if (! is_array($body)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return view('admin.settings', [
            'contextLabel' => null,
            'settings' => GeneralSettings::fromConfig(),
            'locale' => (string) config('athar.locales.default', 'ar'),
            'template' => ['key' => $template, 'body' => $body],
            'errorState' => null,
        ]);
    }
}
