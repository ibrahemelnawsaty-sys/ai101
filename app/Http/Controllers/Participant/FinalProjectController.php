<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Exceptions\FileException;
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
use App\Services\Notifications\CohortNotices;
use App\Services\Storage\PrivateFileService;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function __construct(
        private readonly PrivateFileService $files,
        private readonly CohortNotices $notices,
    ) {}

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

        $isPastDue = $project->isPastDueAt($now);
        $allowsLate = (bool) $project->getAttribute('allow_late');
        // D-110's own switch: past the deadline blocks submission only when
        // the project forbids late hand-ins; when it allows them the form
        // stays open and the record is simply marked late (mirrors
        // Assignment::scopeOpenFor()'s exact rule).
        $canSubmit = $user->can('submit', $project) && (! $isPastDue || $allowsLate);

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
                : (string) __($isPastDue ? 'project.closed_deadline' : 'project.closed_locked'),
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
            return back()->withErrors(['live_url' => __('project.errors.not_available')]);
        }

        $this->authorize('submit', $project);

        $now = Clock::now();
        $dueAt = $project->getAttribute('due_at');

        // D-110's late-submission switch: past the deadline is refused
        // outright when the project forbids it, exactly like an assignment
        // that does not allow_late (BR-18's own rule, mirrored).
        if ($project->isPastDueAt($now) && ! (bool) $project->getAttribute('allow_late')) {
            return back()->withErrors(['live_url' => __('project.closed_deadline')]);
        }

        $previous = (int) ProjectSubmission::query()
            ->where('final_project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->max('version');

        // D-110's three named deliverables plus the optional logo: each is
        // its own single-file descriptor, stored and recorded in the same
        // transaction so neither a submission that claims a file it does not
        // have, nor a file with no row pointing at it, can exist.
        try {
            DB::transaction(function () use ($request, $project, $user, $now, $dueAt, $previous): void {
                $presentation = $this->files->store(
                    $request->file('presentation_file'),
                    'final-projects/'.$project->getKey(),
                    $user,
                );

                $logoFile = $request->file('logo_file');
                $logo = $logoFile === null ? null : $this->files->store(
                    $logoFile,
                    'final-projects/'.$project->getKey(),
                    $user,
                );

                ProjectSubmission::query()->create([
                    'final_project_id' => $project->getKey(),
                    'user_id' => $user->getKey(),
                    'live_url' => $request->validated('live_url'),
                    'github_url' => $request->validated('github_url'),
                    'presentation_file' => $presentation,
                    'logo_file' => $logo,
                    'description' => $request->validated('description'),
                    'submitted_at' => $now,
                    'is_late' => $dueAt !== null && $now->greaterThan(Clock::toUtc($dueAt)),
                    'version' => $previous + 1,
                ]);
            });
        } catch (FileException $failure) {
            // localizedMessage(), not getMessage() — the latter is the raw
            // translation key, kept language neutral for the log (see
            // DomainException). The same slip lived in AssignmentController.
            return back()->withErrors(['files' => $failure->localizedMessage()]);
        }

        // The same two notices an assignment hand-in sends (D-83).
        $cohortId = (string) $project->getAttribute('cohort_id');
        $this->notices->handedIn(
            $user,
            $cohortId,
            (string) $project->getAttribute('title'),
            route('finalProject'),
            route('trainer.finalProject', ['cohort' => $cohortId]),
        );

        return redirect()
            ->route('finalProject')
            ->with('status', __('project.submitted'));
    }
}
