<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer\Concerns;

use App\Http\Middleware\EnsureCohortScope;

/**
 * The cohort a trainer request may act on is the one `cohort.scope` resolved
 * from the enrolments table — never a cohort id posted by the browser. A form
 * that carries its own `cohort_id` is ignored, not trusted (BR-23).
 *
 * @see BR-23, BR-28 · PRD §4.3 · CONSTITUTION Art. 5, Art. 22
 */
trait ScopedToCohort
{
    protected function scopedCohortId(): ?string
    {
        $value = $this->attributes->get(EnsureCohortScope::ATTRIBUTE);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
