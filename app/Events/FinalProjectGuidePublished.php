<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The final project's guide reached the cohort's trainees (D-127): its Arabic
 * page is published and the project itself is open. Dispatched once per guide
 * — the first time the trainees can read it — and never for the English page.
 *
 * Addressed to a cohort; the listener resolves the roster when it runs (D-51).
 *
 * @see D-127 · D-51 · PRD §9.16.1
 */
final class FinalProjectGuidePublished
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
    ) {}
}
