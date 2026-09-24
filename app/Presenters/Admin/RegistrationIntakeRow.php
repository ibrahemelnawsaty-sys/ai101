<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\CohortStatus;
use App\Models\Cohort;
use App\Models\LandingSetting;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One cohort's registration switch, as the registrations screen shows it
 * (D-117).
 *
 * The switch is read the way the registration form enforces it
 * (LandingSetting::switchIsOn). `acceptsNow` says whether the form takes a
 * registration today — the switch alone does not: the cohort must also be
 * `open`, have a seat, and not be past its closing time. The screen says so
 * rather than let an open switch pass for an open registration.
 *
 * @see BR-07, BR-31 · PRD §9.1.2, §9.18 · D-117
 */
final class RegistrationIntakeRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    /** The landing setting must be eager-loaded; a missing relation is not a missing row. */
    public static function from(Cohort $cohort): self
    {
        $program = self::related($cohort, 'program');
        $status = $cohort->getAttribute('status');
        $status = $status instanceof CohortStatus ? $status : null;

        $open = LandingSetting::switchIsOn($cohort->landingSetting);
        $acceptsNow = $cohort->acceptsRegistrations();

        return new self([
            'id' => (string) $cohort->getKey(),
            'name' => (string) $cohort->getAttribute('name'),
            'programName' => $program?->getAttribute('name_ar') ?? '—',
            'statusLabel' => $status?->label() ?? '—',
            'statusVariant' => self::cohortVariantOf($status),
            'statusIcon' => self::cohortIconOf($status),
            'isOpen' => $open,
            'stateLabel' => __($open ? 'admin.registrations.intake.state_open' : 'admin.registrations.intake.state_closed'),
            'stateVariant' => $open ? 'success' : 'neutral',
            'stateIcon' => $open ? 'check' : 'lock',
            'nextValue' => $open ? '0' : '1',
            'actionLabel' => __($open ? 'admin.registrations.intake.close' : 'admin.registrations.intake.open'),
            'acceptsNow' => $acceptsNow,
            'note' => match (true) {
                ! $open => __('admin.registrations.intake.note_closed'),
                $acceptsNow => __('admin.registrations.intake.note_accepting'),
                default => __('admin.registrations.intake.note_waiting'),
            },
        ]);
    }
}
