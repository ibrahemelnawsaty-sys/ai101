<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An administrator declined a place.
 *
 * The reason travels with the event because the letter must give one. A refusal
 * with no reason is the failure mode this whole notification matrix exists to
 * prevent (art. 7: say what happened AND what to do).
 *
 * @see PRD §9.16.1 · D-51
 */
final class EnrollmentRejected
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $programName,
        public readonly string $reason,
    ) {}
}
