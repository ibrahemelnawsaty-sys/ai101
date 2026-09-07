<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\CohortStatus;
use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One cohort on the admin cohorts table.
 *
 * `seatsTaken` and `capacity` are the numbers behind the capacity bar, and the
 * variant says how close the cohort is to full: a full cohort is critical
 * because approving a registration into it is refused (PRD §9.2.3).
 *
 * The trainer names come from the enrolment rows that ARE the trainer's
 * permission on this cohort (BR-23); an empty list is shown as "none" rather
 * than hidden, because an unstaffed cohort is something to notice.
 *
 * @see PRD §4.2, §7.2, §9.18 · BR-23, BR-26, BR-31
 */
final class CohortRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(Cohort $cohort): self
    {
        $program = self::related($cohort, 'program');
        $status = $cohort->getAttribute('status');
        $status = $status instanceof CohortStatus ? $status : null;

        $capacity = (int) $cohort->getAttribute('capacity');
        $taken = (int) $cohort->getAttribute('seats_taken');

        return new self([
            'id' => (string) $cohort->getKey(),
            'name' => (string) $cohort->getAttribute('name'),
            'programName' => $program?->getAttribute('name_ar') ?? '—',
            'startsAt' => $cohort->getAttribute('start_date'),
            'endsAt' => $cohort->getAttribute('end_date'),
            'capacity' => $capacity,
            'seatsTaken' => $taken,
            'seatsVariant' => self::seatsVariant($taken, $capacity),
            'trainerNames' => self::trainerNames($cohort),
            'statusLabel' => $status?->label() ?? '—',
            'statusVariant' => self::cohortVariantOf($status),
            'statusIcon' => self::cohortIconOf($status),
        ]);
    }

    /**
     * A full cohort is critical, a nearly full one is a warning, and anything
     * else is simply the brand colour — never a judgement about being empty.
     */
    private static function seatsVariant(int $taken, int $capacity): string
    {
        if ($capacity <= 0) {
            return 'default';
        }

        if ($taken >= $capacity) {
            return 'error';
        }

        return $taken / $capacity * 100 >= self::$rateHealthyAt ? 'warning' : 'brand';
    }

    /**
     * @return list<string>
     */
    private static function trainerNames(Cohort $cohort): array
    {
        $trainers = self::related($cohort, 'trainers');

        if ($trainers === null) {
            return [];
        }

        $names = [];

        foreach ($trainers as $trainer) {
            if (! $trainer instanceof User) {
                continue;
            }

            $profile = self::related($trainer, 'profile');
            $names[] = (string) ($profile?->getAttribute('full_name_ar') ?? $trainer->getAttribute('email'));
        }

        return $names;
    }
}
