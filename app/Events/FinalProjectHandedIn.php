<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A participant handed in the final project (D-122). Scalars only (D-51): the
 * listener reads the hand-in again when the queue runs, so the letter carries
 * what is stored, not what the request held.
 *
 * @see FR-NOTIF-15 · PRD §9.14.2, §9.16.1 · D-51, D-122
 */
final class FinalProjectHandedIn
{
    use Dispatchable;

    public function __construct(
        public readonly string $submissionId,
    ) {}
}
