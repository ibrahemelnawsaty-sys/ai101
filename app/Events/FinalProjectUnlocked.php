<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The final project became available to a cohort.
 *
 * Half the marks live here (BR-11), so this is the one schedule change that
 * every participant must hear about rather than discover.
 *
 * @see BR-11 · PRD §9.13, §9.16.1 · D-51
 */
final class FinalProjectUnlocked
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $deadline,
        public readonly string $url,
    ) {}
}
