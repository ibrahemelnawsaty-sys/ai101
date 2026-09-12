<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised once an account and its profile have been persisted, before the
 * address has been verified. The welcome letter and the cohort enrolment that
 * PRD §9.2.3 describes are triggered from here, not from the controller.
 *
 * @see PRD §9.2.3
 */
final class AccountRegistered
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

    public function __construct(public readonly User $user) {}
}
