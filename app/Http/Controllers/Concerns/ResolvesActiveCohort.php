<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Participant\SwitchCohortRequest;
use App\Models\Cohort;
use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * Which cohort a dashboard screen is talking about.
 *
 * The answer is the one the account may actually reach: the remembered choice
 * if it is still reachable, otherwise the first reachable one, otherwise null.
 * A null cohort is an empty state, never an error and never a 403 — a brand new
 * account with no enrolment yet is a normal situation (Art. 17).
 *
 * Reach is decided by the enrolments table on every call, so a remembered id
 * that has been revoked stops working immediately (BR-22, BR-23, BR-28).
 *
 * @see BR-22, BR-23, BR-28 · PRD §4.4 · CONSTITUTION Art. 17, Art. 22
 */
trait ResolvesActiveCohort
{
    protected function activeCohortId(User $user): ?string
    {
        $reachable = $user->accessibleCohortIds();

        if ($reachable === []) {
            return null;
        }

        $remembered = Session::get(SwitchCohortRequest::SESSION_KEY);

        if (is_string($remembered) && in_array($remembered, $reachable, true)) {
            return $remembered;
        }

        return (string) $reachable[0];
    }

    protected function activeCohort(User $user): ?Cohort
    {
        $id = $this->activeCohortId($user);

        if ($id === null) {
            return null;
        }

        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()->with('program')->find($id);

        return $cohort;
    }
}
