<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Http\Controllers\Concerns\OpensNextHandIn;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\StoreProjectEvaluationRequest;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Presenters\Trainer\FinalProjectBrief;
use App\Presenters\Trainer\ProjectSubmissionRow;
use App\Services\Grading\EvaluationRecorder;
use App\Services\Grading\GradingQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
 * @see BR-12, BR-13, BR-14, BR-15, BR-16, BR-23 · FR-ASGN-29, FR-GRADE-15 · PRD §9.14, §9.15 · CONSTITUTION art. 5, art. 6 · D-136
 */
final class FinalProjectController extends Controller
{
    use OpensNextHandIn;
    use ReadsCohortScope;

    /** `?versions=all` lists the earlier versions of a hand-in too (D-143). */
    private const VERSIONS_PARAM = 'versions';

    private const VERSIONS_ALL = 'all';

    public function __construct(
        private readonly EvaluationRecorder $evaluations,
        private readonly GradingQueue $queue,
    ) {}

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
                'showsEarlier' => false,
                'hiddenVersions' => 0,
                'versionsParam' => self::VERSIONS_PARAM,
                'versionsAll' => self::VERSIONS_ALL,
                'selected' => null,
                'errorState' => null,
            ]);
        }

        $selectedId = $this->selectedId($request);
        $showsEarlier = $request->query(self::VERSIONS_PARAM) === self::VERSIONS_ALL;
        $submissions = $this->submissions($project, $selectedId, $showsEarlier);

        return view('trainer.final-project', [
            'contextLabel' => $cohort?->getAttribute('name'),
            'project' => FinalProjectBrief::from($project),
            'submissions' => $submissions,
            'showsEarlier' => $showsEarlier,
            'hiddenVersions' => $showsEarlier ? 0 : $this->hiddenVersions($project, $submissions),
            'versionsParam' => self::VERSIONS_PARAM,
            'versionsAll' => self::VERSIONS_ALL,
            'selected' => $selectedId === null ? null : $submissions->first(
                // ArrayAccess, not ->id: the ViewModel publishes through __get,
                // so only the declared offsetGet() has a type the analyser can read.
                static fn (ProjectSubmissionRow $row): bool => (string) $row['id'] === $selectedId,
            ),
            'errorState' => null,
        ]);
    }

    /**
     * What was handed in for this project: the newest version of each
     * participant's hand-in unless the trainer asks for the earlier ones too
     * (BR-19 keeps them all, and an older one that was handed in again is never
     * the one to mark — Submission's twin scope says it once). The row the panel
     * is open on is always listed, so a link to an earlier version still opens.
     *
     * Waiting for a mark first, oldest first: the order «save and go to the
     * next» walks (GradingQueue, D-136). Only the row the grading panel is open
     * on carries what was handed in: the table never shows it, and each of its
     * files is a signed link to mint (D-121, security review).
     *
     * @return Collection<int, ProjectSubmissionRow>
     */
    private function submissions(FinalProject $project, ?string $selectedId, bool $showsEarlier): Collection
    {
        $maxScore = (float) ($project->getAttribute('max_score') ?? 0);

        $query = ProjectSubmission::query()
            ->with(['user.profile', 'latestEvaluation'])
            ->withExists('evaluations')
            ->where('final_project_id', $project->getKey());

        if (! $showsEarlier) {
            $query->where(static function (Builder $only) use ($selectedId): void {
                $only->newestVersionOnly();

                if ($selectedId !== null) {
                    $only->orWhere('id', $selectedId);
                }
            });
        }

        $rows = $query
            ->orderBy('evaluations_exists')
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        // A row that has a NEWER version is the copy not to mark; only asked when the
        // board lists earlier versions, and only of the rows listed.
        $newest = $showsEarlier
            ? ProjectSubmission::query()->whereIn('id', $rows->modelKeys())->newestVersionOnly()->pluck('id')->flip()
            : null;

        return $rows
            ->map(static fn (ProjectSubmission $row): ProjectSubmissionRow => ProjectSubmissionRow::from(
                $row,
                $maxScore,
                withAnswers: (string) $row->getKey() === $selectedId,
                isSuperseded: $newest !== null && ! $newest->has((string) $row->getKey()),
            ))
            ->values();
    }

    /**
     * How many earlier versions the default listing tucks away: every version
     * there is, minus the rows actually listed (which include an older one whose
     * panel is open, so it is not "hidden").
     *
     * @param  Collection<int, ProjectSubmissionRow>  $listed
     */
    private function hiddenVersions(FinalProject $project, Collection $listed): int
    {
        $all = ProjectSubmission::query()->where('final_project_id', $project->getKey())->count();

        return max(0, $all - $listed->count());
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

        // «Save and go to the next» (FR-ASGN-29, D-136): the flag only chooses
        // where the trainer lands; the mark above is already recorded.
        return $request->wantsNext()
            ? $this->projectAfter($request, $submission, (string) __('grades.recorded'))
            : back()->with('status', __('grades.recorded'));
    }
}
