<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\StoreProjectEvaluationRequest;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Presenters\Trainer\FinalProjectBrief;
use App\Presenters\Trainer\ProjectSubmissionRow;
use App\Services\Grading\EvaluationRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The final project from the trainer's side: reading the brief, and grading
 * it (PRD §9.14, §9.15).
 *
 * D-110 — opening the tab, and every setting of it (the brief, the deadline,
 * the ceiling, the late policy), moved to Admin\FinalProjectController: a
 * trainer here only reads what the administrator published and records a
 * mark, so the two roles can never maintain the same settings.
 *
 * The grade goes through EvaluationRecorder, which owns the ceiling (BR-12)
 * and the mandatory feedback (BR-13).
 *
 * The screen is handed presenters, never models: it reads `$project->isUnlocked`
 * and `$row->stateVariant`, and both are decisions taken here (art. 5).
 *
 * @see BR-12, BR-13, BR-15, BR-16, BR-23 · PRD §9.14, §9.15 · CONSTITUTION art. 5, art. 6
 */
final class FinalProjectController extends Controller
{
    use ReadsCohortScope;

    public function __construct(private readonly EvaluationRecorder $evaluations) {}

    public function index(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        /** @var FinalProject|null $project */
        $project = $cohort?->finalProject()->with('unlocker.profile')->first();

        if ($project === null) {
            return view('trainer.final-project', [
                'contextLabel' => $cohort?->getAttribute('name'),
                'project' => FinalProjectBrief::missing(),
                'submissions' => collect(),
                'selected' => null,
                'errorState' => null,
            ]);
        }

        $selectedId = $this->selectedId($request);
        $submissions = $this->submissions($project, $selectedId);

        return view('trainer.final-project', [
            'contextLabel' => $cohort?->getAttribute('name'),
            'project' => FinalProjectBrief::from($project),
            'submissions' => $submissions,
            'selected' => $selectedId === null ? null : $submissions->first(
                // ArrayAccess, not ->id: the ViewModel publishes through __get,
                // so only the declared offsetGet() has a type the analyser can read.
                static fn (ProjectSubmissionRow $row): bool => (string) $row['id'] === $selectedId,
            ),
            'errorState' => null,
        ]);
    }

    /**
     * Everything handed in for this project, newest first. Only the row the
     * grading panel is open on carries what was handed in: the table never
     * shows it, and each of its files is a signed link to mint (D-121,
     * security review).
     *
     * @return Collection<int, ProjectSubmissionRow>
     */
    private function submissions(FinalProject $project, ?string $selectedId): Collection
    {
        $maxScore = (float) ($project->getAttribute('max_score') ?? 0);

        return ProjectSubmission::query()
            ->with(['user.profile', 'latestEvaluation'])
            ->where('final_project_id', $project->getKey())
            ->orderByDesc('submitted_at')
            ->get()
            ->map(static fn (ProjectSubmission $row): ProjectSubmissionRow => ProjectSubmissionRow::from(
                $row,
                $maxScore,
                withAnswers: (string) $row->getKey() === $selectedId,
            ))
            ->values();
    }

    /**
     * The grading panel, open on `?grade={submission}`.
     *
     * The id is matched against the rows loaded for THIS project, so an id
     * belonging to another cohort's project finds nothing and the panel stays
     * shut. The write endpoint runs its own policy check regardless — a hidden
     * panel is not a permission (art. 5, art. 22).
     */
    private function selectedId(Request $request): ?string
    {
        $id = $request->query('grade');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** BR-12, BR-13 — record the project grade. */
    public function grade(StoreProjectEvaluationRequest $request, ProjectSubmission $submission): RedirectResponse
    {
        /** @var \App\Models\User $trainer */
        $trainer = $request->user();

        $this->evaluations->recordProject(
            $trainer,
            $submission,
            (float) $request->validated('score'),
            (string) $request->validated('feedback'),
        );

        return back()->with('status', __('grades.recorded'));
    }
}
