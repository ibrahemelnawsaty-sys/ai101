<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Middleware\EnsureCohortScope;
use App\Models\Cohort;
use Illuminate\Http\Request;

/**
 * Reading the cohort that `cohort.scope` resolved for this request.
 *
 * Every trainer screen asks here rather than reading a cohort id out of the
 * query string: the middleware already decided which cohort this account may
 * act on, from the enrolments table, and refused anything else with a 403 and
 * an audit entry (BR-23).
 *
 * A trainer with no cohort assigned yet gets null — an empty state, not an
 * error (Art. 17).
 *
 * @see BR-22, BR-23, BR-28 · PRD §4.3 · CONSTITUTION Art. 17, Art. 22
 */
trait ReadsCohortScope
{
    protected function scopedCohortId(Request $request): ?string
    {
        $value = $request->attributes->get(EnsureCohortScope::ATTRIBUTE);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function scopedCohort(Request $request): ?Cohort
    {
        $id = $this->scopedCohortId($request);

        if ($id === null) {
            return null;
        }

        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()->with('program')->find($id);

        return $cohort;
    }
}
