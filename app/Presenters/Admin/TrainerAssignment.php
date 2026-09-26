<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Services\Cohorts\PrimaryCoordinator;
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
        $coordinators = self::related($cohort, 'coordinators');

        $people = [];

        foreach (($trainers ?? []) as $trainer) {
            if ($trainer instanceof User) {
                $people[] = TrainerOption::from($trainer);
            }
        }

        $coordinatorPeople = [];
        // D-124 — the primary coordinator, as the one reader of the column
        // decides it; a choice exists only between two coordinators or more.
        $primaryId = app(PrimaryCoordinator::class)->idOf($cohort);
        $coordinatorCount = count(array_filter(
            is_iterable($coordinators) ? [...$coordinators] : [],
            static fn (mixed $person): bool => $person instanceof User,
        ));

        foreach (($coordinators ?? []) as $coordinator) {
            if ($coordinator instanceof User) {
                $coordinatorPeople[] = CoordinatorOption::from($coordinator, $primaryId, $coordinatorCount > 1);
            }
        }

        return new self([
            'id' => (string) $cohort->getKey(),
            'name' => (string) $cohort->getAttribute('name'),
            'programName' => self::text($program, 'name_ar'),
            'trainers' => $people,
            'coordinators' => $coordinatorPeople,
            // D-124 — without a primary coordinator the cohort can be neither
            // opened for registration nor started, and its tickets go to the
            // general supervisor; the panel says so where it can be fixed.
            'needsPrimary' => $coordinatorCount > 1 && $primaryId === null,
            'hasNoCoordinator' => $coordinatorCount === 0,
        ]);
    }
}
