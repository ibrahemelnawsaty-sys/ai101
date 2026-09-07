<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\ProgramStatus;
use App\Models\Program;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One programme on the admin programmes table.
 *
 * `isArchived` decides whether the row still offers the archive action, and it
 * is a reading of the stored status — the archive endpoint re-checks the policy
 * regardless, because hiding a button is not protection (art. 5).
 *
 * @see BR-31, BR-36 · PRD §4.2, §7.2, §7.8, §9.18
 */
final class ProgramRow extends ViewModel
{
    use PresentsVariants;

    public static function from(Program $program): self
    {
        $status = $program->getAttribute('status');
        $status = $status instanceof ProgramStatus ? $status : null;

        $hours = $program->getAttribute('hours');

        return new self([
            'id' => (string) $program->getKey(),
            'name' => (string) $program->getAttribute('name_ar'),
            'slug' => (string) $program->getAttribute('slug'),
            'hours' => $hours === null ? '—' : (int) $hours,
            'cohortsCount' => (int) ($program->getAttribute('cohorts_count') ?? 0),
            'isArchived' => $status === ProgramStatus::Archived,
            'statusLabel' => $status?->label() ?? '—',
            'statusVariant' => self::programVariantOf($status),
            'statusIcon' => self::programIconOf($status),
        ]);
    }
}
