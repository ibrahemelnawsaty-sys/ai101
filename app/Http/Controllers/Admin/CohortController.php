<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignTrainerRequest;
use App\Http\Requests\Admin\StoreCohortRequest;
use App\Http\Requests\Admin\UpdateCohortRequest;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\User;
use App\Presenters\Admin\CohortForm;
use App\Presenters\Admin\CohortRow;
use App\Presenters\Admin\TrainerAssignment;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cohorts (PRD §4.2, §7.2).
 *
 * `pass_score` and `min_attendance_rate` are set here, and they are the two
 * conditions a certificate is judged against — both must be met and neither
 * compensates for the other (BR-26). Changing them changes who qualifies, so
 * every edit goes into the trail with its previous values.
 *
 * `registration_closes_at` is typed in Riyadh wall time and stored in UTC by
 * Clock, never by a parse in this file (Art. 11).
 *
 * @see BR-26, BR-31 · PRD §4.2, §7.2 · CONSTITUTION Art. 8, Art. 11
 */
final class CohortController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Cohort::class);

        $query = Cohort::query()
            ->with(['program', 'trainers.profile'])
            ->withCount(['enrollments', 'sessions'])
            ->orderByDesc('start_date');

        $program = $request->query('program');

        if (is_string($program) && $program !== '') {
            $query->where('program_id', $program);
        }

        $status = $request->query('status');

        if (is_string($status) && CohortStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $programs = Program::query()->orderBy('name_ar')->get();

        return view('admin.cohorts', [
            'contextLabel' => null,
            'cohorts' => $query->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(static fn (Cohort $cohort): CohortRow => CohortRow::from($cohort)),
            'programOptions' => Options::fromModels(
                $programs,
                static fn (Program $item): string => (string) $item->getAttribute('name_ar'),
            ),
            'statusOptions' => Options::fromEnum(CohortStatus::class),
            'editing' => $this->editing($request),
            'assigning' => $this->assigning($request),
            'errorState' => null,
        ]);
    }

    /**
     * The editor panel, open on `?edit=new` or on `?edit={id}`.
     *
     * The row is fetched by id and the write is left to the policy: `viewAny`
     * admits the administrator to this list, and `update` is what the PATCH
     * endpoint re-checks before anything is written (art. 5).
     */
    private function editing(Request $request): ?CohortForm
    {
        $edit = $request->query('edit');

        if (! is_string($edit) || $edit === '') {
            return null;
        }

        if ($edit === 'new') {
            $program = $request->query('program');

            return CohortForm::blank(is_string($program) && $program !== '' ? $program : null);
        }

        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()->find($edit);

        return $cohort === null ? null : CohortForm::from($cohort);
    }

    /** The trainer-assignment panel, open on `?trainers={id}`. */
    private function assigning(Request $request): ?TrainerAssignment
    {
        $id = $request->query('trainers');

        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()->with(['program', 'trainers.profile'])->find($id);

        return $cohort === null ? null : TrainerAssignment::from($cohort);
    }

    public function store(StoreCohortRequest $request): RedirectResponse
    {
        $columns = $request->columns();
        $closesAt = $request->registrationClosesAtRiyadh();

        $columns['registration_closes_at'] = $closesAt === null
            ? null
            : Clock::fromRiyadh($closesAt);

        /** @var Cohort $cohort */
        $cohort = Cohort::query()->create($columns);

        $this->audit->log('cohort.created', $cohort, null, [
            'pass_score' => (int) $cohort->getAttribute('pass_score'),
            'min_attendance_rate' => (int) $cohort->getAttribute('min_attendance_rate'),
        ]);

        return redirect()
            ->route('admin.cohorts.index')
            ->with('status', __('admin.cohorts.created'));
    }

    public function update(UpdateCohortRequest $request, Cohort $cohort): RedirectResponse
    {
        $before = $this->audit->snapshot($cohort, [
            'pass_score', 'min_attendance_rate', 'capacity', 'status',
        ]);

        $columns = $request->columns();
        $closesAt = $request->registrationClosesAtRiyadh();

        $columns['registration_closes_at'] = $closesAt === null
            ? null
            : Clock::fromRiyadh($closesAt);

        $cohort->fill($columns);

        $this->audit->log(
            action: 'cohort.updated',
            entity: $cohort,
            before: $before,
            after: $this->audit->snapshot($cohort, [
                'pass_score', 'min_attendance_rate', 'capacity', 'status',
            ]),
        );

        $cohort->save();

        return back()->with('status', __('admin.cohorts.updated'));
    }

    /**
     * Assign a trainer to a cohort (PRD §4.2). The assignment *is* the
     * permission: `cohort.scope` and every policy read this same enrolment row,
     * so attaching here is what lets that trainer reach that cohort, and
     * nothing else does (BR-23).
     */
    public function attachTrainer(AssignTrainerRequest $request, Cohort $cohort): RedirectResponse
    {
        $trainer = $request->trainer();

        $enrollment = Enrollment::query()->firstOrNew([
            'cohort_id' => $cohort->getKey(),
            'user_id' => $trainer->getKey(),
        ]);

        $enrollment->fill([
            'role_in_cohort' => EnrollmentRole::Trainer->value,
            'status' => EnrollmentStatus::Active->value,
            'enrolled_at' => $enrollment->getAttribute('enrolled_at') ?? Clock::now(),
        ]);

        $this->audit->log('cohort.trainer_attached', $cohort, null, [
            'trainer_id' => (string) $trainer->getKey(),
        ]);

        $enrollment->save();

        return back()->with('status', __('admin.cohorts.trainer_attached'));
    }

    /**
     * Remove a trainer from a cohort. The enrolment is withdrawn rather than
     * deleted, so the record that they once taught it survives; their reach
     * ends immediately because every scope query counts active rows only.
     */
    public function detachTrainer(Cohort $cohort, User $trainer): RedirectResponse
    {
        $this->authorize('assignTrainer', $cohort);

        $enrollment = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('user_id', $trainer->getKey())
            ->where('role_in_cohort', EnrollmentRole::Trainer->value)
            ->first();

        if ($enrollment === null) {
            return back();
        }

        $this->audit->log('cohort.trainer_detached', $cohort, [
            'status' => $enrollment->getAttribute('status')?->value,
        ], ['trainer_id' => (string) $trainer->getKey()]);

        $enrollment->forceFill(['status' => EnrollmentStatus::Withdrawn->value])->save();

        return back()->with('status', __('admin.cohorts.trainer_detached'));
    }
}
