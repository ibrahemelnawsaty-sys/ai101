<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One of the six final-project deadline reminders fell due (D-122). Scalars
 * only (D-51); the deadline travels as an ISO instant so the letter says how
 * long is left at the moment it is SENT, not when the tick claimed it.
 *
 * @see PRD §9.14, §9.16.1 · D-51, D-83, D-122
 */
final class FinalProjectReminderDue
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $projectId,
        public readonly string $projectTitle,
        public readonly string $dueAtIso,
        public readonly string $dueAtLabel,
    ) {}
}
