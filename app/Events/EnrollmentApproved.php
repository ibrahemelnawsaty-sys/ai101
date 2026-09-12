<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An administrator approved somebody's place in a cohort.
 *
 * The decision is the moment worth announcing, not the row that records it: a
 * participant who is told days later by noticing a changed screen has been
 * left to guess.
 *
 * @see PRD §9.16.1 · D-51
 */
final class EnrollmentApproved
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
        public readonly string $cohortName,
    ) {}
}
