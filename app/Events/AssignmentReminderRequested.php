<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trainer asked to remind everyone who has not handed an assignment in.
 *
 * Scalars only (D-51). The deadline travels as an ISO instant, not as words:
 * the letter says how long is left at the moment it is SENT, and the queue may
 * run minutes after the press.
 *
 * @see PRD §9.11.3 · FR-ASGN-30 · D-51, D-68
 */
final class AssignmentReminderRequested
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $assignmentId,
        public readonly string $assignmentTitle,
        public readonly string $dueAtIso,
        public readonly string $dueAtLabel,
    ) {}
}
