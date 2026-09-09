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
 * @see BR-11 · PRD §9.11, §9.16.1 · D-51
 */
final class AssignmentPublished
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $assignmentTitle,
        public readonly int $maxScore,
        public readonly string $dueAt,
        public readonly string $url,
    ) {}
}
