<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A certificate was issued.
 *
 * The serial travels with the event because it is the one fact the letter must
 * state exactly (PRD §9.17) and the one the reader will quote back when
 * verifying it.
 *
 * @see BR-20, BR-25 · PRD §9.17, §9.16.1 · D-51
 */
final class CertificateIssued
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
        public readonly string $serial,
        public readonly string $downloadUrl,
    ) {}
}
