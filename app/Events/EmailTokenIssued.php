<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\EmailTokenType;
use App\Models\EmailToken;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised the moment a single-use e-mail token is created, carrying the raw
 * token exactly once — it is never stored, only its hash is (PRD §9.2.3).
 *
 * This is the seam between the HTTP layer and the mail layer: the controller
 * mints the token and announces it, and whoever owns outbound e-mail listens
 * and sends it. Nothing here blocks the request, and no listener is required
 * for the flow to remain correct — an unsent letter is a delivery failure, not
 * a broken account.
 *
 * @see BR-30 · PRD §9.2.3, §9.3.3
 */
final class EmailTokenIssued
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
        public readonly EmailToken $token,
        public readonly EmailTokenType $type,
        public readonly string $plainToken,
        public readonly string $url,
    ) {}
}
