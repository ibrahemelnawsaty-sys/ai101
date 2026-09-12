<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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

    /*
     * The queue stores this object, so it must not store a whole model.
     *
     * Without SerializesModels, Laravel PHP-serializes the event into
     * `jobs.payload` — and a User's $attributes carries `password_hash` and
     * `remember_token`. A failed send keeps that row in `failed_jobs` for the
     * fourteen days `queue:prune-failed` allows, and the nightly dump carries it
     * further. With the trait only the class and the key are written, and the
     * row is re-read when the job runs (D-65).
     */
    use SerializesModels;

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
        public readonly string $reason,
    ) {}
}
