<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trainer recorded a score for a piece of work.
 *
 * Carries the numbers, not the evaluation row: by the time the queue runs, the
 * row may have been revised, and a letter that reports a score the trainer has
 * already changed is worse than a late one.
 *
 * @see BR-11, BR-19 · PRD §9.15, §9.16.1 · D-51
 */
final class GradeRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $itemName,
        public readonly int $score,
        public readonly int $max,
    ) {}
}
