<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\AssignmentStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\SubmissionStatus;
use App\Events\AssignmentReminderRequested;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\RemindAssignmentRequest;
use App\Http\Requests\Trainer\ReviseEvaluationRequest;
use App\Http\Requests\Trainer\StoreEvaluationRequest;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\Submission;
use App\Models\User;
use App\Presenters\Shared\TotalsWarning;
use App\Presenters\Support\Options;
use App\Presenters\Support\Present;
use App\Presenters\Trainer\GradingForm;
use App\Presenters\Trainer\SubmissionRow;
use App\Presenters\Trainer\SubmissionStats;
use App\Services\Audit\AuditLogger;
use App\Services\Grading\EvaluationRecorder;
use App\Services\Grading\ScoreCalculator;
use App\Services\Mail\CohortAudience;
use App\Services\Notifications\InAppNotifier;
use App\Services\Time\Clock;
use App\Support\Dates;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The submissions board and the grading form (PRD §9.11.3, §9.15).
 *
 * A trainer sees their own cohorts and no others: the list starts from the
 * cohort `cohort.scope` resolved, and the policy checks each row again before
 * it is graded or downloaded (BR-23).
 *
 * Grading itself is not done here. EvaluationRecorder owns BR-12 (the ceiling),
 * BR-13 (feedback is mandatory) and BR-14 (an amendment needs a reason, is
 * audited and notifies the trainee); this controller hands it the validated
 * values and nothing more.
 *
 * The board hands the template presenters, never Eloquent models: the screen
 * reads `$row->stateVariant` and `$selected->maxScore`, and both are decisions
 * about how the number looks, taken here on the server (art. 5, art. 6).
 *
 * @see BR-11, BR-12, BR-13, BR-14, BR-19, BR-22, BR-23, FR-ASGN-30 · PRD §9.11.3, §9.15 · CONSTITUTION art. 5, art. 6
 */
final class SubmissionController extends Controller
{
    use ExportsCsv;
    use ReadsCohortScope;

    private const PER_PAGE = 50;

    /** The Article 17 screen name. */
    private const SCREEN = 'trainer-submissions';

    /**
     * The query string that opens the grading panel on one submission.
     *
     * The board has no per-submission URL of its own: `cohort.scope` decides
     * which cohort the request may act on, and this names the row inside it
     * (trainer.submissions?cohort=&submission=). It used to be spelled `grade`
     * in this class and in the template, and `submission` everywhere else, so
     * the link the board rendered opened nothing.
     */
    private const SELECTED_PARAM = 'submission';

    public function __construct(
        private readonly EvaluationRecorder $evaluations,
        private readonly ScoreCalculator $scores,
        private readonly AuditLogger $audit,
        private readonly CohortAudience $audience,
        private readonly InAppNotifier $notifier,
    ) {}

    public function index(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('trainer.submissions', [
                'contextLabel' => null,
                'rows' => collect(),
                'stats' => SubmissionStats::of(0, 0, 0, 0),
                'assignmentOptions' => [],
                'statusOptions' => Options::fromEnum(SubmissionStatus::class),
                'selected' => null,
                'totalsWarning' => null,
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
                'bulkDownloadHref' => null,
                'remindAssignmentId' => null,
                'selectedParam' => self::SELECTED_PARAM,
            ]);
        }

        $assignments = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('due_at')
            ->get();

        $page = $this->query($request, $assignments->modelKeys())
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows = $page->through(
            static fn (Submission $row): SubmissionRow => SubmissionRow::from($row),
        );

        return view('trainer.submissions', [
            'contextLabel' => $cohort->getAttribute('name'),
            'rows' => $rows,
            'stats' => $this->stats($cohort, $assignments),
            'assignmentOptions' => Options::fromModels(
                $assignments,
                static fn (Assignment $item): string => (string) $item->getAttribute('title'),
            ),
            'statusOptions' => Options::fromEnum(SubmissionStatus::class),
            'selected' => $this->selected($request, $assignments->modelKeys()),
            // BR-11 — the assignment ceilings should add up to 50. A mismatch
            // is a warning to the trainer, never a block (PROJECT-CONTRACT §7).
            'totalsWarning' => TotalsWarning::forCohort($this->scores, $cohort),
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::of($rows->isEmpty()),
            // The bulk download needs an assignment in its path, so the link
            // only exists once the board is filtered to one. Building it
            // unconditionally threw UrlGenerationException and took the whole
            // board down with it — a disabled button is not a missing route.
            'bulkDownloadHref' => $this->bulkDownloadHref($request, $assignments->modelKeys()),
            'remindAssignmentId' => $this->remindAssignmentId($request, $assignments, $cohort),
            'selectedParam' => self::SELECTED_PARAM,
        ]);
    }

    /**
     * The assignment the "remind" button would address, or null when there is
     * no button to show. Like the bulk download it needs ONE assignment, so it
     * exists only once the board is filtered to one — and only when that one
     * is published, still open, and has somebody left to remind.
     *
     * The button used to live in a per-row branch keyed on `hasSubmission`,
     * which every row on this board had: it could never render (D-68).
     *
     * @param  EloquentCollection<int, Assignment>  $assignments
     */
    private function remindAssignmentId(Request $request, EloquentCollection $assignments, Cohort $cohort): ?string
    {
        $requested = $request->query('assignment');

        if (! is_string($requested) || $requested === '') {
            return null;
        }

        $assignment = $assignments->first(
            static fn (Assignment $item): bool => (string) $item->getKey() === $requested,
        );

        if (! $assignment instanceof Assignment
            || ! $assignment->isPublished()
            || $assignment->isPastDueAt(Clock::now())) {
            return null;
        }

        $pending = $this->audience->yetToSubmit((string) $cohort->getKey(), $requested);

        return $pending->isEmpty() ? null : $requested;
    }

    /**
     * The board query, bound to the scoped cohort's assignments and narrowed by
     * the three filters the toolbar offers. A filter can only narrow: the
     * `whereIn` on the cohort's own assignment ids is applied first and is not
     * removable from the query string (BR-23).
     *
     * @param  array<int, mixed>  $assignmentIds
     * @return Builder<Submission>
     */
    private function query(Request $request, array $assignmentIds): Builder
    {
        $query = Submission::query()
            ->with(['assignment', 'user.profile', 'latestEvaluation'])
            ->whereIn('assignment_id', $assignmentIds)
            ->orderByDesc('submitted_at');

        $assignment = $request->query('assignment');

        if (is_string($assignment) && in_array($assignment, array_map('strval', $assignmentIds), true)) {
            $query->where('assignment_id', $assignment);
        }

        $status = $request->query('status');

        if (is_string($status) && SubmissionStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';

            $query->whereHas('user', static function (Builder $user) use ($term): void {
                $user->where('email', 'like', $term)
                    ->orWhereHas('profile', static function (Builder $profile) use ($term): void {
                        $profile->where('first_name_ar', 'like', $term)
                            ->orWhere('last_name_ar', 'like', $term)
                            ->orWhere('first_name_en', 'like', $term)
                            ->orWhere('last_name_en', 'like', $term);
                    });
            });
        }

        return $query;
    }

    /**
     * The grading panel, open on `?submission={id}`.
     *
     * The id is resolved inside the cohort's own assignments, so an id from
     * another cohort finds nothing and the panel stays shut. The write endpoint
     * it posts to runs its own policy check regardless — a hidden panel is not
     * a permission (art. 5, art. 22).
     *
     * @param  array<int, mixed>  $assignmentIds
     */
    private function selected(Request $request, array $assignmentIds): ?GradingForm
    {
        $id = $request->query(self::SELECTED_PARAM);

        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var Submission|null $submission */
        $submission = Submission::query()
            ->with(['assignment', 'user.profile', 'latestEvaluation'])
            ->whereIn('assignment_id', $assignmentIds)
            ->whereKey($id)
            ->first();

        return $submission === null ? null : GradingForm::from($submission);
    }

    /**
     * The bulk-download link, or null when the board is not filtered to one
     * assignment. The id is checked against the scoped cohort's own assignments
     * first, so a foreign id never reaches the URL generator (BR-23).
     *
     * @param  array<int, mixed>  $assignmentIds
     */
    private function bulkDownloadHref(Request $request, array $assignmentIds): ?string
    {
        $assignment = $request->query('assignment');

        if (! is_string($assignment) || ! in_array($assignment, array_map('strval', $assignmentIds), true)) {
            return null;
        }

        return route('trainer.submissions.bulkDownload', ['assignment' => $assignment]);
    }

    /**
     * The four counters, over the whole cohort rather than the page on screen.
     *
     * `missing` is the gap: how many (active participant × published
     * assignment) pairs have produced no submission at all. It is counted from
     * distinct pairs, because BR-19 keeps every version rather than replacing
     * it and three versions of one assignment are still one thing handed in.
     *
     * @param  EloquentCollection<int, Assignment>  $assignments
     */
    private function stats(Cohort $cohort, EloquentCollection $assignments): SubmissionStats
    {
        $assignmentIds = $assignments->modelKeys();

        if ($assignmentIds === []) {
            return SubmissionStats::of(0, 0, 0, 0);
        }

        $base = Submission::query()->whereIn('assignment_id', $assignmentIds);

        $participants = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->where('status', EnrollmentStatus::Active->value)
            ->count();

        $publishedCount = $assignments
            ->filter(static fn (Assignment $item): bool => $item->getAttribute('status') === AssignmentStatus::Published)
            ->count();

        // The pair, not either column on its own: one participant may appear on
        // many assignments and one assignment on many participants.
        //
        // Counted through a derived table rather than COUNT(DISTINCT a, b),
        // which only MySQL accepts — SQLite rejects it outright, so the
        // multi-column form would work in production and fail every test.
        $handedIn = DB::query()
            ->fromSub(
                (clone $base)->select('user_id', 'assignment_id')->distinct()->toBase(),
                'handed_in',
            )
            ->count();

        return SubmissionStats::of(
            submitted: (clone $base)->count(),
            late: (clone $base)->where('is_late', true)->count(),
            missing: ($participants * $publishedCount) - $handedIn,
            awaitingGrading: (clone $base)->whereDoesntHave('evaluations')->count(),
        );
    }

    /** BR-12, BR-13 — record a grade against one submission. */
    public function grade(StoreEvaluationRequest $request, Submission $submission): RedirectResponse
    {
        /** @var User $trainer */
        $trainer = $request->user();

        $this->evaluations->record(
            $trainer,
            $submission,
            (float) $request->validated('score'),
            (string) $request->validated('feedback'),
        );

        return back()->with('status', __('grades.recorded'));
    }

    /** BR-14 — amend a recorded grade, with a reason, audited and notified. */
    public function revise(ReviseEvaluationRequest $request, Evaluation $evaluation): RedirectResponse
    {
        /** @var User $trainer */
        $trainer = $request->user();

        $this->evaluations->revise(
            $trainer,
            $evaluation,
            (float) $request->validated('score'),
            (string) $request->validated('feedback'),
            (string) $request->validated('revision_reason'),
        );

        return back()->with('status', __('grades.revised'));
    }

    /**
     * Remind everyone who has not handed this assignment in (FR-ASGN-30).
     *
     * It used to authorise, write an audit row and say "we sent the reminder"
     * — and send nothing: no event, no listener, no notice. The trainer then
     * treated the silent cohort as warned (D-68). Now, in order:
     *
     *   1. refuse after the deadline — there is no time left to remind about
     *      (a temporary assumption, even with late submission allowed: D-68);
     *   2. refuse when nobody is left to remind;
     *   3. CLAIM the send atomically on `last_reminded_at`, so a double click,
     *      or a trainer and an administrator pressing together, write to each
     *      person once. A rate limit per actor would let the second click in;
     *   4. write the in-app notices now, queue the letters, audit the count.
     */
    public function remind(RemindAssignmentRequest $request, Assignment $assignment): RedirectResponse
    {
        $now = Clock::now();

        if ($assignment->isPastDueAt($now)) {
            return back()->with('error', __('trainer.submissions.remind_closed'));
        }

        $cohortId = (string) $assignment->getAttribute('cohort_id');
        $pending = $this->audience->yetToSubmit($cohortId, (string) $assignment->getKey());

        if ($pending->isEmpty()) {
            return back()->with('warning', __('trainer.submissions.remind_none'));
        }

        $cooldown = max(0, (int) config('athar.assignments.reminder_cooldown_minutes'));

        // toBase(): a plain UPDATE, so Eloquent does not stamp updated_at with
        // the framework clock rather than Clock (BR-07).
        $claimed = Assignment::query()
            ->whereKey($assignment->getKey())
            ->where(static function (Builder $query) use ($now, $cooldown): void {
                $query->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', $now->subMinutes($cooldown));
            })
            ->toBase()
            ->update(['last_reminded_at' => $now]);

        if ($claimed === 0) {
            return back()->with('warning', __('trainer.submissions.remind_recent'));
        }

        $title = (string) $assignment->getAttribute('title');
        $dueAt = $assignment->getAttribute('due_at');
        $replacements = [
            'assignment' => $title,
            'countdown' => Present::durationLabel($dueAt, $now),
        ];

        $this->notifier->notify(
            $pending->map(static fn (User $user): string => (string) $user->getKey()),
            'assignment_due_reminder',
            (string) __('notifications.types.assignment_due_reminder.title', $replacements),
            (string) __('notifications.types.assignment_due_reminder.body', $replacements),
            route('assignments.show', ['assignment' => $assignment->getKey()]),
            $now,
        );

        AssignmentReminderRequested::dispatch(
            cohortId: $cohortId,
            assignmentId: (string) $assignment->getKey(),
            assignmentTitle: $title,
            dueAtIso: Clock::toUtc($dueAt)->toIso8601ZuluString(),
            dueAtLabel: Dates::dateTime($dueAt),
        );

        $this->audit->log('assignment.reminded', $assignment, null, ['recipients' => $pending->count()]);

        return back()->with('status', __('trainer.submissions.reminded'));
    }

    /**
     * Every file handed in for one assignment, as a single archive.
     *
     * The archive is built by App\Services\Storage, which is the only part that
     * knows where a submitted file lives; that service is not in this slice, so
     * until it lands the endpoint authorises correctly and then says plainly
     * that the download is unavailable rather than sending an empty archive.
     */
    public function bulkDownload(Assignment $assignment): RedirectResponse
    {
        $this->authorize('downloadAll', $assignment);

        $this->audit->log('assignment.bulk_download', $assignment);

        // `error`, not withErrors(['files' => …]): the board has no field named
        // files, and the layout toasts only status, error and warning — so the
        // message was never shown (D-68).
        return back()->with('error', __('trainer.submissions.bulk_unavailable'));
    }

    /** The board as a CSV, scoped and filtered exactly like the board itself. */
    public function export(Request $request): Response
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->authorize('export', $cohort);

        $assignmentIds = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->pluck('id')
            ->all();

        $rows = $this->query($request, $assignmentIds)->get();

        $lines = ["\u{FEFF}".$this->csvRow([
            __('trainer.submissions.export.participant'),
            __('trainer.submissions.export.assignment'),
            __('trainer.submissions.export.version'),
            __('trainer.submissions.export.late'),
            __('trainer.submissions.export.score'),
        ])];

        foreach ($rows as $row) {
            $lines[] = $this->csvRow([
                (string) ($row->user?->profile?->getAttribute('full_name_ar') ?? ''),
                (string) ($row->assignment?->getAttribute('title') ?? ''),
                (string) $row->getAttribute('version'),
                $row->getAttribute('is_late') ? '1' : '0',
                (string) ($row->latestEvaluation?->getAttribute('score') ?? ''),
            ]);
        }

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="athar-submissions.csv"',
        ]);
    }
}
