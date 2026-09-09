<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sign-in arrived from a device this account has not used before.
 *
 * The instant is carried rather than read later, because the letter is queued
 * and a security notice that reports the wrong time is worse than none. The
 * address is the truthful remote address — forwarded headers are not trusted
 * (bootstrap/app.php, art. 8, art. 22).
 *
 * @see BR-28 · PRD §9.3, §9.16.1 · D-51
 */
final class NewDeviceLogin
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $ip,
        public readonly CarbonImmutable $at,
    ) {}
}
