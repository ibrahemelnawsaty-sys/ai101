<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\SwitchCohortRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The cohort switcher in the header (PRD §4.4).
 *
 * One person can be a trainer in one cohort and a participant in another. The
 * switch is a preference, not a permission: the FormRequest refuses any cohort
 * the account cannot reach, and every screen re-checks reach for itself on
 * every request anyway (BR-28).
 *
 * @see BR-22, BR-23, BR-28 · PRD §4.4 · CONSTITUTION Art. 22
 */
final class CohortSwitchController extends Controller
{
    public function __invoke(SwitchCohortRequest $request): RedirectResponse
    {
        $request->session()->put(SwitchCohortRequest::SESSION_KEY, $request->cohortId());

        return back();
    }
}
