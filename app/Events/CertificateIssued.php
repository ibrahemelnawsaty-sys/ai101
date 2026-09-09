<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

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

    public function __construct(
        public readonly User $user,
        public readonly string $serial,
        public readonly string $downloadUrl,
    ) {}
}
