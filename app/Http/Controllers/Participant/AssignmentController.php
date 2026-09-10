<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\AssignmentStatus;
use App\Enums\EvaluationEntity;
use App\Exceptions\FileException;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\SubmitAssignmentRequest;
use App\Models\Assignment;
use App\Models\Evaluation;
use App\Models\Submission;
use App\Models\User;
use App\Models\Week;
use App\Presenters\Participant\AssignmentPresenter;
use App\Presenters\Participant\AssignmentsSummaryPresenter;
use App\Presenters\Participant\SubmissionPresenter;
use App\Presenters\Participant\SubmissionVersionPresenter;
use App\Presenters\Participant\WeekPresenter;
use App\Services\Grading\ScoreCalculator;
use App\Services\Storage\PrivateFileService;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assignments as the participant sees them (PRD §9.11).
 *
 * A draft assignment is invisible: the list and the detail screen both filter
 * on `published`, so an unpublished task cannot be reached by guessing its id —
 * the policy refuses it as well (BR-22).
 *
 * Submissions are versioned, never replaced: every hand-in keeps the previous
 * one (BR-19). The version counter and the late flag are decided here against
 * the server clock and the assignment's own `allow_late` switch (BR-18); the
 * files themselves are stored by the storage layer, outside the web root.
 *
 * @see BR-17, BR-18, BR-19, BR-22 · PRD §9.11 · CONSTITUTION Art. 5, Art. 11
 */
final class AssignmentController extends Controller
{
    use ResolvesActiveCohort;

    /** The Article 17 screen names, and the names of their loading skeletons. */
    private const SCREEN_INDEX = 'assignments';

    public function __construct(
        private readonly ScoreCalculator $scores,
        private readonly PrivateFileService $files,
    ) {}

    /**
     * The uploaded files, as a flat list.
     *
     * `$request->file('files')` hands back a single UploadedFile when one was
     * sent, an array when several were, and null when none. Normalising it here
     * keeps every caller from having to remember that.
     *
     * @return array<int, mixed>
     */
    private function uploads(SubmitAssignmentRequest $request): array
    {
        $files = $request->file('files');

        if ($files === null) {
            return [];
        }

        return array_values(is_array($files) ? $files : [$files]);
    }

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Assignment::class);

        $cohort = $this->activeCohort($user);

        if ($cohort === null) {
            return view('participant.assignments.index', [
                'weeks' => new Collection,
                'summary' => AssignmentsSummaryPresenter::none(),
                'errorState' => null,
                'screen' => self::SCREEN_INDEX,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $now = Clock::now();

        $assignments = Assignment::query()
            ->with([
                'week',
                'submissions' => static fn ($query) => $query
                    ->where('user_id', $user->getKey())
                    ->orderByDesc('version'),
            ])
            ->where('cohort_id', $cohort->getKey())
            ->where('status', AssignmentStatus::Published->value)
            ->orderBy('due_at')
            ->get();

        $evaluations = $this->evaluationsByAssignment($user, $assignments);

        $submitted = [];

        foreach ($assignments as $assignment) {
            $submitted[(string) $assignment->getKey()] = $assignment->submissions->isNotEmpty();
        }

        $byWeek = $assignments->groupBy(
            static fn (Assignment $item): string => (string) ($item->getAttribute('week_id') ?? ''),
        );

        $present = fn (Collection $group): Collection => $group->map(
            fn (Assignment $item): AssignmentPresenter => AssignmentPresenter::from(
                $item,
                $item->submissions->first(),
                $evaluations[(string) $item->getKey()] ?? null,
                $now,
            ),
        )->values();

        $groups = $cohort->weeks()
            ->orderBy('index')
            ->get()
            ->map(fn (Week $week) => WeekPresenter::from(
                $week,
                $now,
                new Collection,
                $present($byWeek->get((string) $week->getKey(), new Collection)),
            ))
            ->values();

        // `assignments.week_id` is nullable, so a published task need not belong
        // to a week. Grouping by week alone hid such a task completely — and a
        // mandatory one still counts towards the mark (BR-11), so it must be
        // reachable. It goes on the same general shelf the resources screen uses.
        $loose = $byWeek->get('', new Collection);

        if ($loose->isNotEmpty()) {
            $groups->push(WeekPresenter::unscheduled(
                (string) __('assignments.unscheduled_group'),
                new Collection,
                $present($loose),
            ));
        }

        return view('participant.assignments.index', [
            'weeks' => $groups,
            'summary' => AssignmentsSummaryPresenter::from(
                $user,
                $cohort,
                $this->scores,
                $assignments,
                $submitted,
            ),
            'errorState' => null,
            'screen' => self::SCREEN_INDEX,
            'screenState' => ScreenState::of($assignments->isEmpty()),
        ]);
    }

    /**
     * The participant's own marks, keyed by the assignment they belong to.
     *
     * An evaluation points at a submission, so the map is built from this
     * account's submissions and from nobody else's (BR-22). Reading it in one
     * query is what keeps the weekly list free of an N+1 (art. 19).
     *
     * @param  Collection<int, Assignment>  $assignments
     * @return array<string, Evaluation>
     */
    private function evaluationsByAssignment(User $user, $assignments): array
    {
        /** @var array<string, string> $assignmentBySubmission */
        $assignmentBySubmission = [];

        foreach ($assignments as $assignment) {
            foreach ($assignment->submissions as $submission) {
                $assignmentBySubmission[(string) $submission->getKey()] = (string) $assignment->getKey();
            }
        }

        if ($assignmentBySubmission === []) {
            return [];
        }

        $evaluations = Evaluation::query()
            ->with('evaluator.profile')
            ->where('user_id', $user->getKey())
            ->where('entity_type', EvaluationEntity::Assignment)
            ->whereIn('entity_id', array_keys($assignmentBySubmission))
            ->orderBy('evaluated_at')
            ->get();

        $latest = [];

        foreach ($evaluations as $evaluation) {
            $assignmentId = $assignmentBySubmission[(string) $evaluation->getAttribute('entity_id')] ?? null;

            if ($assignmentId !== null) {
                // Ordered ascending, so the last one wins: a revised mark
                // replaces the one it revised on screen (BR-14).
                $latest[$assignmentId] = $evaluation;
            }
        }

        return $latest;
    }

    public function show(Assignment $assignment, Request $request): View
    {
        $this->authorize('view', $assignment);

        /** @var User $user */
        $user = $request->user();

        $versions = Submission::query()
            ->where('assignment_id', $assignment->getKey())
            ->where('user_id', $user->getKey())
            ->orderByDesc('version')
            ->get();

        $now = Clock::now();
        $dueAt = $assignment->getAttribute('due_at');
        $isLate = $dueAt !== null && $now->greaterThan(Clock::toUtc($dueAt));

        $assignment->loadMissing('week');

        $latest = $versions->first();

        $evaluation = $latest === null ? null : Evaluation::query()
            ->with('evaluator.profile')
            ->where('user_id', $user->getKey())
            ->where('entity_type', EvaluationEntity::Assignment)
            ->whereIn('entity_id', $versions->modelKeys())
            ->orderByDesc('evaluated_at')
            ->first();

        $canSubmit = $request->user()?->can('submit', $assignment) === true
            && (! $isLate || (bool) $assignment->getAttribute('allow_late'));

        return view('participant.assignments.show', [
            'assignment' => AssignmentPresenter::from($assignment, $latest, $evaluation, $now),
            'submission' => $latest === null
                ? null
                : SubmissionPresenter::fromAssignment(
                    $latest,
                    $evaluation,
                    (float) $assignment->getAttribute('max_score'),
                ),
            'versions' => $versions->map(
                static fn (Submission $item): SubmissionVersionPresenter => SubmissionVersionPresenter::from($item),
            ),
            'canSubmit' => $canSubmit,
            // A disabled area must say why (PRD §9.9.4's rule, applied here too).
            'closedReason' => $canSubmit
                ? null
                : ($isLate
                    ? (string) __('assignments.errors.deadline_passed')
                    : (string) __('assignments.closed_body')),
            'errorState' => null,
        ]);
    }

    /**
     * BR-18, BR-19. The deadline is re-read here; a page rendered before it
     * passed cannot buy a late hand-in, and a late hand-in on a task that
     * forbids them is refused rather than silently accepted.
     */
    public function submit(SubmitAssignmentRequest $request, Assignment $assignment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $now = Clock::now();
        $dueAt = $assignment->getAttribute('due_at');
        $isLate = $dueAt !== null && $now->greaterThan(Clock::toUtc($dueAt));

        if ($isLate && ! (bool) $assignment->getAttribute('allow_late')) {
            return back()->withErrors(['files' => __('assignments.errors.deadline_passed')]);
        }

        $previous = (int) Submission::query()
            ->where('assignment_id', $assignment->getKey())
            ->where('user_id', $user->getKey())
            ->max('version');

        // Files are stored through App\Services\Storage, which sniffs the real
        // type from the bytes and writes outside the web root under a random
        // name (PRD §12.5).
        //
        // This used to read `'files' => []`, under a comment saying the storage
        // service "is not part of this slice; until it lands". The service HAD
        // landed — PrivateFileService was complete and had zero callers — so
        // every file a trainee attached was validated, counted as a valid
        // hand-in, and then thrown away. The trainee was told it worked and the
        // trainer saw nothing. A stale comment described the code, and the code
        // was believed.
        //
        // Storing and recording happen in ONE transaction: a file that fails to
        // store must not leave a submission claiming to hold it, and a row that
        // fails to write must not leave an orphan on disk.
        try {
            DB::transaction(function () use ($request, $assignment, $user, $now, $isLate, $previous): void {
                $descriptors = [];

                foreach ($this->uploads($request) as $file) {
                    $descriptors[] = $this->files->store(
                        $file,
                        'submissions/'.$assignment->getKey(),
                        $user,
                    );
                }

                Submission::query()->create([
                    'assignment_id' => $assignment->getKey(),
                    'user_id' => $user->getKey(),
                    'files' => $descriptors,
                    'github_url' => $request->validated('github_url'),
                    'note' => $request->validated('note'),
                    'submitted_at' => $now,
                    'is_late' => $isLate,
                    'version' => $previous + 1,
                    'status' => 'submitted',
                ]);
            });
        } catch (FileException $failure) {
            // The service refuses a file whose bytes do not match what it claims
            // to be, among other things. The trainee gets the reason in Arabic
            // and nothing is half-accepted.
            return back()->withErrors(['files' => $failure->getMessage()]);
        }

        return redirect()
            ->route('assignments.show', $assignment)
            ->with('status', __('assignments.submitted'));
    }
}
