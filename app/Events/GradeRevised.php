<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trainer changed a score that had already been given.
 *
 * A separate event from `GradeRecorded` on purpose: the reader needs to know
 * their grade MOVED and why, which is a different sentence from being told a
 * grade for the first time.
 *
 * @see BR-11 · PRD §9.15, §9.16.1 · D-51
 */
final class GradeRevised
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $itemName,
        public readonly int $score,
        public readonly int $max,
        public readonly string $reason,
    ) {}
}
