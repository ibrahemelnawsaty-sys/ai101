<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trainer published a new assignment to a cohort.
 *
 * Addressed to a COHORT, not a person: the listener resolves who is actively
 * enrolled at the moment the queue runs, which is the correct roster — somebody
 * who withdrew between the publish and the send should not be chased for work
 * they no longer owe.
 *
 * It carries the assignment's id, never a URL. It used to carry
 * route('trainer.assignments') — the TRAINER board — so every participant who
 * pressed the letter's button got a 403 (D-68). The listener now builds the
 * participant's link itself, where LetterContractTest reads the route name.
 * Every field is a scalar (D-51); dispatch with named arguments, because two
 * UUID strings swapped by position still type-check.
 *
 * @see BR-11 · FR-NOTIF-13 · PRD §9.11, §9.16.1 · D-51, D-68
 */
final class AssignmentPublished
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $assignmentId,
        public readonly string $assignmentTitle,
        public readonly int $maxScore,
        public readonly string $dueAt,
    ) {}
}
