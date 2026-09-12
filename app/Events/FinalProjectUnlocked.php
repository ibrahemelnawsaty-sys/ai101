<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The final project was opened to a cohort — the PRD §9.16.1 row "final
 * project opened: the whole cohort, at opening, platform and e-mail".
 *
 * Addressed to a cohort; the listener resolves who is actively enrolled when
 * it runs (D-51) and builds the link itself. Dispatched only on the move from
 * locked to unlocked — re-saving an open project announces nothing (D-77).
 *
 * @see PRD §9.14, §9.16.1 · D-51, D-77
 */
final class FinalProjectUnlocked
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $deadline,
    ) {}
}
