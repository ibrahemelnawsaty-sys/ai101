<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\AssignmentStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
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
 * @see BR-11, BR-17, BR-18, BR-23 · PRD §9.11.3 · CONSTITUTION Art. 5, Art. 22
 */
final class AssignmentController extends Controller
{
    use ReadsCohortScope;

    public function __construct(private readonly ScoreCalculator $scores) {}

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

        Assignment::query()->create(array_merge($request->columns(), [
            'cohort_id' => $request->cohortId(),
            'created_by' => $trainer->getKey(),
        ]));

        return back()->with('status', __('trainer.assignments.created'));
    }

    public function update(UpdateAssignmentRequest $request, Assignment $assignment): RedirectResponse
    {
        $assignment->fill($request->columns())->save();

        return back()->with('status', __('trainer.assignments.updated'));
    }
}
