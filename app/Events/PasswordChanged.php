<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised after a password has been changed and every other session of that
 * account has been invalidated (BR-29). The mail layer listens and sends the
 * "your password was changed at …" notice required by PRD §9.3.3.
 *
 * @see BR-29 · PRD §9.3.3
 */
final class PasswordChanged
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
        public readonly CarbonImmutable $changedAt,
        public readonly string $source,
    ) {}
}
