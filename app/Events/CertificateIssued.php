<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A certificate was issued to this person — the PRD §9.16.1 row "certificate
 * issued: the trainee, at issue, platform and e-mail".
 *
 * Dispatched by the certificate controller after the serial has been
 * allocated and the row committed — never inside the allocation callback,
 * which may be retried with a different serial. Nothing dispatched it before
 * D-77, so no certificate letter was ever sent.
 *
 * `$url` is the holder's certificate page, not the download route: nothing
 * generates a certificate file yet, so that route answers 404 (D-77).
 *
 * @see BR-25 · PRD §9.16.1, §9.17 · D-51, D-77
 */
final class CertificateIssued
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $serial,
        public readonly string $url,
        public readonly string $verifyUrl,
    ) {}
}
