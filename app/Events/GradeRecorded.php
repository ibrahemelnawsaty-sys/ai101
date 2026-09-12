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
        // Already formatted, not numeric. `evaluations.score` and `max_score`
        // are decimal(5,2), and these were declared `int`: under strict_types a
        // score of 7.5 is a TypeError, not a rounding. Passing the string the
        // recorder already built for the in-app notice also makes the letter and
        // the notification say the same number (D-65).
        public readonly string $score,
        public readonly string $max,
    ) {}
}
