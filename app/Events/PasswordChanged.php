<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

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

    public function __construct(
        public readonly User $user,
        public readonly CarbonImmutable $changedAt,
        public readonly string $source,
    ) {}
}
