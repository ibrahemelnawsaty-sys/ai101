<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A session of a cohort was moved to another time — the other half of the PRD
 * §9.16.1 row "session cancelled or postponed: the whole cohort, at the
 * change, platform and e-mail". Cancelling has its own event and its own
 * words; this one carries the new time instead of a reason.
 *
 * Scalars only, addressed to a cohort (D-51).
 *
 * @see PRD §9.8, §9.16.1 · FR-NOTIF-12 · D-83
 */
final class SessionRescheduled
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $sessionTitle,
        public readonly string $newTimeLabel,
    ) {}
}
