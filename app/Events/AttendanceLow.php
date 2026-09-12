<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A participant's attendance fell under the rate their certificate needs.
 *
 * Both numbers travel: the reader must see how far they are, not merely that
 * they are short. The rate is DERIVED from the rows that exist (BR-26) and is
 * never typed in anywhere.
 *
 * @see BR-26 · PRD §9.9, §9.16.1 · D-51
 */
final class AttendanceLow
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
        public readonly int $currentRate,
        public readonly int $requiredRate,
    ) {}
}
