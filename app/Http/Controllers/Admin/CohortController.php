<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignCoordinatorRequest;
use App\Http\Requests\Admin\AssignTrainerRequest;
use App\Http\Requests\Admin\DetachCoordinatorRequest;
use App\Http\Requests\Admin\DetachTrainerRequest;
use App\Http\Requests\Admin\SeatParticipantRequest;
use App\Http\Requests\Admin\SetPrimaryCoordinatorRequest;
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
use App\Services\Cohorts\PrimaryCoordinator;
use App\Services\Credentials\AccountInviter;
use App\Services\Messages\ThreadProvisioner;
use App\Services\Tickets\TicketWorkflow;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 * @see BR-26, BR-31 · PRD §4.2, §7.2 · CONSTITUTION Art. 8, Art. 11 · D-84, D-117, D-124
 */
final class CohortController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ThreadProvisioner $threads,
        private readonly AccountInviter $inviter,
        private readonly PrimaryCoordinator $primary,
        private readonly TicketWorkflow $workflow,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Cohort::class);

        $query = Cohort::query()
            ->with(['program', 'trainers.profile', 'coordinators.profile'])
            ->withCount(['enrollments', 'sessions'])
            ->orderByDesc('start_date');

        $program = $request->query('program');

        if (is_string($program) && $program !== '') {
            $query->where('program_id', $program);
        }

        // `state`, not `status` — see admin/cohorts.blade.php (D-124).
        $status = $request->query('state');

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
            'seating' => $this->seating($request->query('participants')),
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
        $cohort = Cohort::query()->with(['program', 'trainers.profile', 'coordinators.profile'])->find($id);

        return $cohort === null ? null : TrainerAssignment::from($cohort);
    }

    /**
     * The seat-an-existing-participant panel, open on `?participants={id}`
     * (D-84, moved here by D-117). Its write is SeatParticipantRequest's and
     * UserPolicy's to allow; this only names the cohort on the card.
     */
    private function seating(mixed $id): ?CohortRow
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()->with(['program', 'trainers.profile', 'coordinators.profile'])->find($id);

        return $cohort === null ? null : CohortRow::from($cohort);
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
     * Seat an existing participant account in this cohort: the enrolment, the
     * card and the cohort's conversations, as an invitation gives them (D-84).
     * It moved here from the account's own page when D-117 gave that page to
     * the system administrator and left this decision with the supervisor.
     * UserPolicy answers for the account as the request answered for the
     * cohort, so the rule on who may be seated lives in one place.
     */
    public function seatParticipant(SeatParticipantRequest $request, Cohort $cohort): RedirectResponse
    {
        $participant = $request->participant();

        $this->authorize('enroll', $participant);

        $seated = $this->inviter->enrollExisting($participant, $cohort);

        return back()->with(
            $seated ? 'status' : 'warning',
            __($seated ? 'admin.cohorts.participant_seated' : 'admin.cohorts.participant_already'),
        );
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
        $trainerId = (string) $trainer->getKey();

        // D-124 — the enrolment row is one per person and cohort: a
        // coordinator assigned here as a trainer leaves the coordination. The
        // rule AssignTrainerRequest asked is asked again under the cohort's
        // lock, so two requests cannot both take the last coordinator away.
        $leftCoordination = DB::transaction(function () use ($cohort, $trainerId): bool {
            $locked = $this->lockCohort($cohort);
            $wasCoordinator = $this->primary->isCoordinatorOf($locked, $trainerId);
            $refusal = $this->primary->departureRefusal($locked, $trainerId);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['trainer' => AssignTrainerRequest::message($refusal)]);
            }

            $enrollment = Enrollment::query()->firstOrNew([
                'cohort_id' => $locked->getKey(),
                'user_id' => $trainerId,
            ]);

            $enrollment->fill([
                'role_in_cohort' => EnrollmentRole::Trainer->value,
                'status' => EnrollmentStatus::Active->value,
                'enrolled_at' => $enrollment->getAttribute('enrolled_at') ?? Clock::now(),
            ]);

            $this->audit->log('cohort.trainer_attached', $locked, null, [
                'trainer_id' => $trainerId,
            ]);

            $enrollment->save();

            if ($wasCoordinator) {
                $this->forgetPrimary($locked, $trainerId);
            }

            return $wasCoordinator;
        });

        if ($leftCoordination) {
            $this->workflow->rehome(Clock::now(), $cohort);
        }

        // The announcement channel, the group, and a direct line to each
        // participant (PRD §9.13, D-82).
        $this->threads->seatTrainer($trainer, $cohort);

        return back()->with('status', __('admin.cohorts.trainer_attached'));
    }

    /**
     * Remove a trainer from a cohort. The enrolment is withdrawn rather than
     * deleted, so the record that they once taught it survives; their reach
     * ends immediately because every scope query counts active rows only.
     */
    public function detachTrainer(DetachTrainerRequest $request, Cohort $cohort, User $trainer): RedirectResponse
    {
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

    /**
     * Assign a coordinator to a cohort — mirrors attachTrainer() exactly. The
     * enrolment IS the permission: it is what `EnsureCohortScope` and
     * `attendanceStaffOf()` read afterwards (BR-23, D-105).
     */
    public function attachCoordinator(AssignCoordinatorRequest $request, Cohort $cohort): RedirectResponse
    {
        $coordinator = $request->coordinator();
        $coordinatorId = (string) $coordinator->getKey();

        DB::transaction(function () use ($cohort, $coordinatorId): void {
            $locked = $this->lockCohort($cohort);

            // D-124 — a single coordinator is primary without being chosen.
            // When another joins, the primary one stays primary: written down
            // now — whatever the column held, empty or naming someone who has
            // since left — or the cohort would lose its primary coordinator
            // (and the route its tickets take) the moment it gained one.
            $current = $this->primary->idOf($locked);

            if ($current !== null && $current !== $coordinatorId && $locked->getAttribute('primary_coordinator_id') !== $current) {
                $this->writePrimary($locked, $current, 'kept_on_new_coordinator');
            }

            $enrollment = Enrollment::query()->firstOrNew([
                'cohort_id' => $locked->getKey(),
                'user_id' => $coordinatorId,
            ]);

            $enrollment->fill([
                'role_in_cohort' => EnrollmentRole::Coordinator->value,
                'status' => EnrollmentStatus::Active->value,
                'enrolled_at' => $enrollment->getAttribute('enrolled_at') ?? Clock::now(),
            ]);

            $this->audit->log('cohort.coordinator_attached', $locked, null, [
                'coordinator_id' => $coordinatorId,
            ]);

            $enrollment->save();
        });

        return back()->with('status', __('admin.cohorts.coordinator_attached'));
    }

    /**
     * Remove a coordinator from a cohort — mirrors detachTrainer() exactly.
     */
    public function detachCoordinator(DetachCoordinatorRequest $request, Cohort $cohort, User $coordinator): RedirectResponse
    {
        $coordinatorId = (string) $coordinator->getKey();

        // D-124 — asked again under the cohort's lock: two removals of the
        // last two coordinators at the same moment cannot both pass.
        $detached = DB::transaction(function () use ($cohort, $coordinatorId): bool {
            $locked = $this->lockCohort($cohort);

            $enrollment = Enrollment::query()
                ->where('cohort_id', $locked->getKey())
                ->where('user_id', $coordinatorId)
                ->where('role_in_cohort', EnrollmentRole::Coordinator->value)
                ->first();

            if ($enrollment === null) {
                return false;
            }

            $refusal = $this->primary->departureRefusal($locked, $coordinatorId);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['coordinator' => DetachCoordinatorRequest::message($refusal)]);
            }

            $this->audit->log('cohort.coordinator_detached', $locked, [
                'status' => $enrollment->getAttribute('status')?->value,
            ], ['coordinator_id' => $coordinatorId]);

            $enrollment->forceFill(['status' => EnrollmentStatus::Withdrawn->value])->save();

            // The primary coordinator went only because one coordinator
            // remains, who is primary on their own now.
            $this->forgetPrimary($locked, $coordinatorId);

            return true;
        });

        if (! $detached) {
            return back();
        }

        // D-124 — their tickets go back to the primary coordinator now, not at
        // the next scheduled pass.
        $this->workflow->rehome(Clock::now(), $cohort);

        return back()->with('status', __('admin.cohorts.coordinator_detached'));
    }

    /**
     * D-124 — choose the cohort's primary coordinator: the one a support
     * ticket reaches first. SetPrimaryCoordinatorRequest admits the cohort's
     * active coordinators alone.
     */
    public function setPrimaryCoordinator(SetPrimaryCoordinatorRequest $request, Cohort $cohort): RedirectResponse
    {
        $coordinatorId = $request->coordinatorId();

        DB::transaction(function () use ($cohort, $coordinatorId): void {
            $locked = $this->lockCohort($cohort);

            // Asked again under the lock: a coordinator removed a moment ago
            // is not made primary by a page that still listed them.
            if (! $this->primary->isCoordinatorOf($locked, $coordinatorId)) {
                throw ValidationException::withMessages(['coordinator_id' => (string) __('admin.cohorts.primary_not_coordinator')]);
            }

            $this->writePrimary($locked, $coordinatorId, 'chosen');
        });

        return back()->with('status', __('admin.cohorts.primary_set'));
    }

    /** The cohort's row, locked for the rest of the transaction (D-124). */
    private function lockCohort(Cohort $cohort): Cohort
    {
        /** @var Cohort $locked */
        $locked = Cohort::query()->whereKey($cohort->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /**
     * Every write of `primary_coordinator_id` goes through here, and each is
     * in the audit trail before it is saved (art. 8) — chosen by the
     * supervisor, kept when a second coordinator joined, or cleared when the
     * one it named left.
     */
    private function writePrimary(Cohort $cohort, ?string $coordinatorId, string $reason): void
    {
        $this->audit->log('cohort.primary_coordinator_set', $cohort, [
            'primary_coordinator_id' => $cohort->getAttribute('primary_coordinator_id'),
        ], [
            'primary_coordinator_id' => $coordinatorId,
            'reason' => $reason,
        ]);

        $cohort->update(['primary_coordinator_id' => $coordinatorId]);
    }

    /** The column forgets a coordinator who is no longer one here. */
    private function forgetPrimary(Cohort $cohort, string $coordinatorId): void
    {
        if ($cohort->getAttribute('primary_coordinator_id') === $coordinatorId) {
            $this->writePrimary($cohort, null, 'coordinator_left');
        }
    }
}
