<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An administrator approved somebody's place in a cohort.
 *
 * The decision is the moment worth announcing, not the row that records it: a
 * participant who is told days later by noticing a changed screen has been
 * left to guess.
 *
 * @see PRD §9.16.1 · D-51
 */
final class EnrollmentApproved
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $cohortName,
    ) {}
}
