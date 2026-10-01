<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Presenters\Admin\GeneralSettings;
use Illuminate\Contracts\View\View;

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
 * There is no write here (D-148): the screen's form posted six fields its request
 * did not know, and the controller recorded «saved» in the audit trail without
 * writing a value anywhere. The e-mail templates are the settings that really save,
 * and they have their own controller (D-136).
 *
 * @see BR-36 · PRD §9.18 · CONSTITUTION Art. 11, Art. 6 · D-117, D-148
 */
final class SettingController extends Controller
{
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
}
