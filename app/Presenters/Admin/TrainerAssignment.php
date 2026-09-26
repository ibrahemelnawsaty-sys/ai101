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
        // decides it. A choice exists only between two coordinators or more
        // who can act (an active account in a coordinating role); a suspended
        // one is listed, never offered.
        $primary = app(PrimaryCoordinator::class);
        $primaryId = $primary->idOf($cohort);
        $eligible = $primary->coordinatorIds($cohort);
        $coordinatorCount = count($eligible);

        foreach (($coordinators ?? []) as $coordinator) {
            if ($coordinator instanceof User) {
                $canChoose = $coordinatorCount > 1 && in_array((string) $coordinator->getKey(), $eligible, true);
                $coordinatorPeople[] = CoordinatorOption::from($coordinator, $primaryId, $canChoose);
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
