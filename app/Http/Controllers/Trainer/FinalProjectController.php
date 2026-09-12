<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Events\FinalProjectUnlocked;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\StoreProjectEvaluationRequest;
use App\Http\Requests\Trainer\UnlockFinalProjectRequest;
use App\Models\FinalProject;
use App\Models\Notification;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Presenters\Trainer\FinalProjectBrief;
use App\Presenters\Trainer\ProjectSubmissionRow;
use App\Services\Audit\AuditLogger;
use App\Services\Grading\EvaluationRecorder;
use App\Services\Mail\CohortAudience;
use App\Services\Notifications\InAppNotifier;
use App\Services\Time\Clock;
use App\Support\Dates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The final project from the trainer's side: opening it, and grading it
 * (PRD §9.14, §9.15).
 *
 * BR-15, BR-16 — the tab opens for a whole cohort at once, and only a trainer
 * of that cohort or an administrator may open it. Until then the brief does not
 * reach a participant response at all.
 *
 * The grade goes through EvaluationRecorder, which owns the ceiling (BR-12) and
 * the mandatory feedback (BR-13).
 *
 * The screen is handed presenters, never models: it reads `$project->isUnlocked`
 * and `$row->stateVariant`, and both are decisions taken here (art. 5).
 *
 * @see BR-12, BR-13, BR-15, BR-16, BR-23 · PRD §9.14, §9.15 · CONSTITUTION art. 5, art. 6
 */
final class FinalProjectController extends Controller
{
    use ReadsCohortScope;

    /** Notification matrix slug of PRD §9.16.1, stored in notifications.type. */
    public const NOTIFICATION_TYPE_UNLOCKED = 'final_project_unlocked';

    public function __construct(
        private readonly EvaluationRecorder $evaluations,
        private readonly AuditLogger $audit,
        private readonly InAppNotifier $notifier,
        private readonly CohortAudience $audience,
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
                'selected' => null,
                'errorState' => null,
            ]);
        }

        $submissions = $this->submissions($project);

        return view('trainer.final-project', [
            'contextLabel' => $cohort?->getAttribute('name'),
            'project' => FinalProjectBrief::from($project),
            'submissions' => $submissions,
            'selected' => $this->selected($request, $submissions),
            'errorState' => null,
        ]);
    }

    /**
     * Everything handed in for this project, newest first.
     *
     * @return Collection<int, ProjectSubmissionRow>
     */
    private function submissions(FinalProject $project): Collection
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
            ))
            ->values();
    }

    /**
     * The grading panel, open on `?grade={submission}`.
     *
     * The id is matched against the rows already loaded for THIS project, so an
     * id belonging to another cohort's project finds nothing and the panel
     * stays shut. The write endpoint runs its own policy check regardless — a
     * hidden panel is not a permission (art. 5, art. 22).
     *
     * @param  Collection<int, ProjectSubmissionRow>  $submissions
     */
    private function selected(Request $request, Collection $submissions): ?ProjectSubmissionRow
    {
        $id = $request->query('grade');

        if (! is_string($id) || $id === '') {
            return null;
        }

        return $submissions->first(
            // ArrayAccess, not ->id: the ViewModel publishes through __get, so
            // only the declared offsetGet() has a type the analyser can read.
            static fn (ProjectSubmissionRow $row): bool => (string) $row['id'] === $id,
        );
    }

    /** BR-15, BR-16 — open (or close) the tab for the whole cohort. */
    public function unlock(UnlockFinalProjectRequest $request, FinalProject $project): RedirectResponse
    {
        /** @var User $trainer */
        $trainer = $request->user();

        $unlocks = $request->unlocks();
        $wasUnlocked = (bool) $project->getAttribute('is_unlocked');

        $before = $this->audit->snapshot($project, ['is_unlocked', 'unlocked_at', 'unlocked_by']);

        $project->setAttribute('is_unlocked', $unlocks);
        $project->setAttribute('unlocked_at', $unlocks ? Clock::now() : null);
        $project->setAttribute('unlocked_by', $unlocks ? $trainer->getKey() : null);

        $this->audit->log(
            action: $unlocks ? 'final_project.unlocked' : 'final_project.locked',
            entity: $project,
            before: $before,
            after: $this->audit->snapshot($project, ['is_unlocked', 'unlocked_at', 'unlocked_by']),
            actor: $trainer,
        );

        $project->save();

        // Only the move from locked to unlocked announces. Every save with the
        // flag set used to write the cohort another notice, and no letter was
        // ever sent (D-77).
        if ($unlocks && ! $wasUnlocked) {
            $this->notifyCohort($project);

            FinalProjectUnlocked::dispatch(
                (string) $project->getAttribute('cohort_id'),
                Dates::dateTime($project->getAttribute('due_at')),
            );
        }

        return back()->with('status', __('project.unlock_saved'));
    }

    /**
     * PRD §9.14.1 requires an immediate notice to every participant of the
     * cohort the moment the tab is opened. The type
     * slug is the one in the notification matrix of PRD §9.16.1, so the
     * participant's own preferences apply to it.
     *
     * @see BR-15 · PRD §9.14.1, §9.16.1
     */
    private function notifyCohort(FinalProject $project): void
    {
        $at = Clock::now();
        $link = Route::has('finalProject') ? route('finalProject') : null;

        $replacements = [
            'datetime' => Dates::dateTime($project->getAttribute('due_at')),
        ];

        $title = (string) __('notifications.types.final_project_unlocked.title', $replacements);
        $body = (string) __('notifications.types.final_project_unlocked.body', $replacements);

        // Active participants only. The query this replaced read participants()
        // with no status filter, so a withdrawn trainee was still told the
        // final project had opened; and it ignored the bell switch (D-68).
        $this->notifier->notify(
            $this->audience->participants((string) $project->getAttribute('cohort_id'))
                ->map(static fn (User $user): string => (string) $user->getKey()),
            self::NOTIFICATION_TYPE_UNLOCKED,
            $title,
            $body,
            $link,
            $at,
        );
    }

    /** BR-12, BR-13 — record the project grade. */
    public function grade(StoreProjectEvaluationRequest $request, ProjectSubmission $submission): RedirectResponse
    {
        /** @var User $trainer */
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
