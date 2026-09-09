<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A participant's attendance fell under the rate their certificate needs.
 *
 * Both numbers travel: the reader must see how far they are, not merely that
 * they are short. The rate is DERIVED from the rows that exist (BR-26) and is
 * never typed in anywhere.
 *
 * @see BR-26 · PRD §9.9, §9.16.1 · D-51
 */
final class AttendanceLow
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly int $currentRate,
        public readonly int $requiredRate,
    ) {}
}
