<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\AssignmentStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Events\AssignmentPublished;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\StoreAssignmentRequest;
use App\Http\Requests\Trainer\UpdateAssignmentRequest;
use App\Models\Assignment;
use App\Models\Enrollment;
use App\Models\User;
use App\Models\Week;
use App\Presenters\Shared\TotalsWarning;
use App\Presenters\Support\Options;
use App\Presenters\Trainer\AssignmentForm;
use App\Presenters\Trainer\AssignmentRow;
use App\Services\Grading\ScoreCalculator;
use App\Services\Mail\CohortAudience;
use App\Services\Notifications\InAppNotifier;
use App\Support\Dates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creating and editing assignments (PRD §9.11.3).
 *
 * A draft is invisible to participants: the participant queries filter on
 * `published` and the policy refuses a draft even by direct id, so visibility
 * is a property of the data and the rules, never of the template (Art. 5).
 *
 * When the ceilings of the published tasks do not add up to 50 the trainer is
 * warned on their own board — and only warned, never blocked (BR-11,
 * PROJECT-CONTRACT §7).
 *
 * ANNOUNCING. A draft announces nothing. The transition to published —
 * whether a new task saved as published or a draft published later through
 * update() — announces once, on both channels: an in-app notice written here
 * and a letter queued through AssignmentPublished. Re-saving a task that is
 * already published announces nothing. There is no published_at column, so
 * publishing, withdrawing to draft and publishing again announces twice; that
 * is accepted and recorded (D-68). Before D-68 it was the other way round on
 * both halves: saving a DRAFT e-mailed the whole cohort its title, and
 * publishing it later told nobody.
 *
 * @see BR-11, BR-17, BR-18, BR-23, FR-NOTIF-13 · PRD §9.11.3, §9.16.1 · CONSTITUTION Art. 5, Art. 22
 */
final class AssignmentController extends Controller
{
    use ReadsCohortScope;

    /** The slug the preferences screen, the lang group and the listener share. */
    private const NOTIFICATION_TYPE = 'assignment_published';

    public function __construct(
        private readonly ScoreCalculator $scores,
        private readonly CohortAudience $audience,
        private readonly InAppNotifier $notifier,
    ) {}

    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('trainer.assignments', [
                'contextLabel' => null,
                'assignments' => collect(),
                'weekOptions' => [],
                'statusOptions' => Options::fromEnum(AssignmentStatus::class),
                'editing' => null,
                'totalsWarning' => null,
                'errorState' => null,
            ]);
        }

        $query = Assignment::query()
            ->with(['week'])
            ->withCount('submissions')
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('due_at');

        $week = $request->query('week');

        if (is_string($week) && $week !== '') {
            $query->where('week_id', $week);
        }

        $status = $request->query('status');

        if (is_string($status) && AssignmentStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        // One count for the whole page, not one per row (art. 19).
        $cohortSize = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->where('status', EnrollmentStatus::Active->value)
            ->count();

        return view('trainer.assignments', [
            'contextLabel' => $cohort->getAttribute('name'),
            'assignments' => $query->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(static fn (Assignment $row): AssignmentRow => AssignmentRow::from($row, $cohortSize)),
            'weekOptions' => Options::fromModels(
                $cohort->weeks()->orderBy('index')->get(),
                static fn (Week $item): string => (string) $item->getAttribute('title'),
            ),
            'statusOptions' => Options::fromEnum(AssignmentStatus::class),
            'editing' => $this->editing($request),
            // BR-11 — a mismatch warns the trainer and never blocks them.
            'totalsWarning' => TotalsWarning::forCohort($this->scores, $cohort),
            'errorState' => null,
        ]);
    }

    /** The editor panel, open on `?edit=new` or `?edit={id}`. */
    private function editing(Request $request): ?AssignmentForm
    {
        $edit = $request->query('edit');
        $cohortId = $this->scopedCohortId($request);

        if (! is_string($edit) || $edit === '' || $cohortId === null) {
            return null;
        }

        if ($edit === 'new') {
            $week = $request->query('week');

            return AssignmentForm::blank(is_string($week) && $week !== '' ? $week : null);
        }

        /** @var Assignment|null $assignment */
        $assignment = Assignment::query()->where('cohort_id', $cohortId)->find($edit);

        return $assignment === null ? null : AssignmentForm::from($assignment);
    }

    public function store(StoreAssignmentRequest $request): RedirectResponse
    {
        /** @var User $trainer */
        $trainer = $request->user();

        $assignment = Assignment::query()->create(array_merge($request->columns(), [
            'cohort_id' => $request->cohortId(),
            'created_by' => $trainer->getKey(),
        ]));

        if ($assignment->isPublished()) {
            $this->announce($assignment);
        }

        return back()->with('status', __('trainer.assignments.created'));
    }

    public function update(UpdateAssignmentRequest $request, Assignment $assignment): RedirectResponse
    {
        $assignment->fill($request->columns())->save();

        // Only the move INTO published announces; re-saving a published task
        // does not write to the cohort again.
        if ($assignment->wasChanged('status') && $assignment->isPublished()) {
            $this->announce($assignment);
        }

        return back()->with('status', __('trainer.assignments.updated'));
    }

    /**
     * Both channels of FR-NOTIF-13, pointed at the PARTICIPANT's page.
     *
     * The bell is written here, now, for the active participants who have not
     * switched it off. The letter is queued; its listener resolves the roster
     * and re-checks the status when it runs (D-51).
     */
    private function announce(Assignment $assignment): void
    {
        $cohortId = (string) $assignment->getAttribute('cohort_id');
        $title = (string) $assignment->getAttribute('title');
        $dueAt = Dates::dateTime($assignment->getAttribute('due_at'));
        $replacements = ['assignment' => $title, 'datetime' => $dueAt];

        $this->notifier->notify(
            $this->audience->participants($cohortId)
                ->map(static fn (User $user): string => (string) $user->getKey()),
            self::NOTIFICATION_TYPE,
            (string) __('notifications.types.assignment_published.title', $replacements),
            (string) __('notifications.types.assignment_published.body', $replacements),
            route('assignments.show', ['assignment' => $assignment->getKey()]),
        );

        AssignmentPublished::dispatch(
            cohortId: $cohortId,
            assignmentId: (string) $assignment->getKey(),
            assignmentTitle: $title,
            maxScore: (int) $assignment->getAttribute('max_score'),
            dueAt: $dueAt,
        );
    }
}
