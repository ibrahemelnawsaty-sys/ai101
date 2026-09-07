<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Support\ViewModel;

/**
 * The general-settings screen (BR-36).
 *
 * The identity values are read from config/athar.php, which reads them from the
 * environment: they are shown here so the centre can see what is deployed, and
 * changing them is a deployment rather than a click (PROJECT-CONTRACT §1).
 *
 * The display timezone is fixed at Asia/Riyadh by art. 11 and is shown
 * read-only WITH its reason, rather than hidden — so nobody goes looking for it
 * in the code.
 *
 * @see BR-07, BR-31, BR-36 · PRD §9.18, §12.5 · CONSTITUTION art. 6, art. 11
 */
final class GeneralSettings extends ViewModel
{
    public static function fromConfig(): self
    {
        $kilobytes = (int) config('athar.uploads.max_kilobytes', 25600);

        return new self([
            'centreName' => (string) config('athar.platform_name', ''),
            'programName' => (string) config('athar.program_name', ''),
            'contactEmail' => (string) config('athar.email', ''),
            'whatsapp' => (string) config('athar.whatsapp', ''),
            'timezone' => (string) config('athar.display_timezone', 'Asia/Riyadh'),
            'maxFileMb' => max(1, (int) round($kilobytes / 1024)),
            'maxFiles' => (int) config('athar.uploads.max_files', 5),
            'notificationDefaults' => NotificationPreferenceRow::all(),
            'emailTemplates' => EmailTemplateRow::all(),
        ]);
    }
}
