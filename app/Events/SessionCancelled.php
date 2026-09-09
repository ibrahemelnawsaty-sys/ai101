<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A session was cancelled or moved.
 *
 * The reason is required by the form that raises it and is required here: a
 * schedule that changes without one is the single most common complaint a
 * training programme receives.
 *
 * @see PRD §9.8, §9.16.1 · D-51
 */
final class SessionCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $sessionTitle,
        public readonly string $reason,
        public readonly string $url,
    ) {}
}
