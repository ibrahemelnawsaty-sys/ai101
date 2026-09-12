<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A session of a cohort was cancelled — the PRD §9.16.1 row "session cancelled
 * or postponed: the whole cohort, at the change, platform and e-mail".
 *
 * Addressed to a cohort (D-51); the listener builds the schedule link. Only
 * the move INTO cancelled dispatches it, and only the cancel endpoint can make
 * that move — the session editor no longer accepts a status at all (D-77).
 *
 * @see PRD §9.8, §9.16.1 · D-51, D-77
 */
final class SessionCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $sessionTitle,
        public readonly string $reason,
    ) {}
}
