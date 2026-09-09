<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A visitor asked to be told when the next cohort opens.
 *
 * Carries an address and nothing else. There is no account, no profile and no
 * name to greet — and inventing one would be the platform pretending to know
 * somebody it does not.
 *
 * @see PRD §9.1.2, §9.16.1 · D-51
 */
final class WaitlistJoined
{
    use Dispatchable;

    public function __construct(
        public readonly string $email,
    ) {}
}
