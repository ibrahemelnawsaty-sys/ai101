<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

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

    public function __construct(public readonly User $user) {}
}
