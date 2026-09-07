<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\SubmitFinalProjectRequest;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Presenters\Participant\EvaluationPresenter;
use App\Presenters\Participant\FinalProjectPresenter;
use App\Presenters\Participant\SubmissionPresenter;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The final project (PRD §9.14).
 *
 * BR-16, stated as a contract with the template: while the project is locked
 * this controller passes `$project = null` and `$isUnlocked = false`. Not one
 * word of the brief — title, description, criteria, attachments, deadline —
 * reaches the response, so viewing the page source before the unlock reveals
 * nothing. Asking for it directly is answered 403 by the policy, not by hidden
 * markup.
 *
 * @see BR-15, BR-16, BR-22 · PRD §9.14 · CONSTITUTION Art. 5
 */
final class FinalProjectController extends Controller
{
    use ResolvesActiveCohort;

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $cohort = $this->activeCohort($user);
        $project = $cohort?->finalProject()->first();
        $now = Clock::now();

        $unlocked = $project instanceof FinalProject
            && (bool) $project->getAttribute('is_unlocked')
            && $user->can('view', $project);

        if (! $unlocked) {
            return view('participant.final-project', [
                'isUnlocked' => false,
                'project' => null,
                'submission' => null,
                'evaluation' => null,
                'canSubmit' => false,
                'closedReason' => null,
                'expectedOpeningLabel' => null,
                'serverNow' => $now,
                'errorState' => null,
            ]);
        }

        /** @var FinalProject $project */
        $submission = ProjectSubmission::query()
            ->with('latestEvaluation.evaluator.profile')
            ->where('final_project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->orderByDesc('version')
            ->first();

        $dueAt = $project->getAttribute('due_at');
        $isLate = $dueAt !== null && $now->greaterThan(Clock::toUtc($dueAt));
        $canSubmit = $user->can('submit', $project) && ! $isLate;

        $evaluation = $submission?->latestEvaluation;

        return view('participant.final-project', [
            'isUnlocked' => true,
            'project' => FinalProjectPresenter::from($project),
            'submission' => $submission === null
                ? null
                : SubmissionPresenter::fromProject(
                    $submission,
                    $evaluation instanceof Evaluation ? $evaluation : null,
                    (float) $project->getAttribute('max_score'),
                ),
            'evaluation' => $evaluation instanceof Evaluation
                ? EvaluationPresenter::from($evaluation)
                : null,
            'canSubmit' => $canSubmit,
            // A closed submission area always says why (PRD §9.9.4's rule).
            'closedReason' => $canSubmit
                ? null
                : (string) __($isLate ? 'project.closed_deadline' : 'project.closed_locked'),
            'expectedOpeningLabel' => null,
            'serverNow' => $now,
            'errorState' => null,
        ]);
    }

    /**
     * Hand in the project. Like an assignment, a submission is a new version
     * and never overwrites the previous one (BR-19).
     */
    public function submit(SubmitFinalProjectRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cohort = $this->activeCohort($user);
        $project = $cohort?->finalProject()->first();

        if (! $project instanceof FinalProject) {
            return back()->withErrors(['files' => __('project.errors.not_available')]);
        }

        $this->authorize('submit', $project);

        $now = Clock::now();
        $dueAt = $project->getAttribute('due_at');

        $previous = (int) ProjectSubmission::query()
            ->where('final_project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->max('version');

        // The stored files are written by App\Services\Storage, which sniffs
        // the real type from the bytes and keeps the file outside the web root
        // (PRD §12.5); that service is not part of this slice.
        ProjectSubmission::query()->create([
            'final_project_id' => $project->getKey(),
            'user_id' => $user->getKey(),
            'files' => [],
            'github_url' => $request->validated('github_url'),
            'description' => $request->validated('description'),
            'submitted_at' => $now,
            'is_late' => $dueAt !== null && $now->greaterThan(Clock::toUtc($dueAt)),
            'version' => $previous + 1,
        ]);

        return redirect()
            ->route('finalProject')
            ->with('status', __('project.submitted'));
    }
}
