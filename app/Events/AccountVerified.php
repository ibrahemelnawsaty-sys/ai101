<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised the moment an address has been confirmed and the account has been
 * seated in the open cohort. The digital card and the ten journey steps are
 * built by the parts that own them, listening here (BR-20, BR-21).
 *
 * @see BR-20, BR-21 · PRD §9.2.3, §9.6, §9.7
 */
final class AccountVerified
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
