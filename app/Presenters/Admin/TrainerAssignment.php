<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * The trainer-assignment panel of one cohort.
 *
 * Attaching a trainer here IS the permission: `cohort.scope` and every policy
 * read the same enrolment row afterwards, so this panel is where a trainer's
 * reach begins and ends (BR-23). It lists who is currently assigned and offers
 * the one form that adds another.
 *
 * @see BR-22, BR-23 · PRD §4.2 · CONSTITUTION art. 22
 */
final class TrainerAssignment extends ViewModel
{
    use PresentsFormValues;

    public static function from(Cohort $cohort): self
    {
        $program = self::related($cohort, 'program');
        $trainers = self::related($cohort, 'trainers');

        $people = [];

        foreach (($trainers ?? []) as $trainer) {
            if ($trainer instanceof User) {
                $people[] = TrainerOption::from($trainer);
            }
        }

        return new self([
            'id' => (string) $cohort->getKey(),
            'name' => (string) $cohort->getAttribute('name'),
            'programName' => self::text($program, 'name_ar'),
            'trainers' => $people,
        ]);
    }
}
