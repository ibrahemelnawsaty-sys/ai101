<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EnrollmentStatus;
use App\Events\EnrollmentApproved;
use App\Events\EnrollmentRejected;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegistrationDecisionRequest;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\User;
use App\Presenters\Admin\RegistrationReview;
use App\Presenters\Admin\RegistrationRow;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\InAppNotifier;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Registration requests awaiting a decision (PRD §9.2.3, §9.18).
 *
 * Approving takes a seat, so the cohort row is locked while the seat count
 * moves: two administrators approving at the same instant must not oversell the
 * last place. Rejecting demands a written reason, which the applicant is told
 * and the trail keeps.
 *
 * @see BR-27, BR-31 · PRD §4.2, §9.2.3 · CONSTITUTION Art. 8
 */
final class RegistrationController extends Controller
{
    use ExportsCsv;

    private const PER_PAGE = 50;

    /** approve() outcome: the request had already been decided. */
    private const ALREADY_DECIDED = 'already_decided';

    /** approve() outcome: the cohort was full under the lock. */
    private const NO_FREE_SEAT = 'no_free_seat';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InAppNotifier $notifier,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Enrollment::class);

        $query = Enrollment::query()
            ->with(['user.profile', 'cohort.program'])
            ->orderBy('created_at');

        // The queue defaults to what is still waiting; a decided request is
        // reachable by asking for its state explicitly.
        $state = $request->query('state');

        if (is_string($state) && EnrollmentStatus::tryFrom($state) !== null) {
            $query->where('status', $state);
        } else {
            $query->where('status', EnrollmentStatus::Pending->value);
        }

        $cohortId = $request->query('cohort');

        if (is_string($cohortId) && $cohortId !== '') {
            $query->where('cohort_id', $cohortId);
        }

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->whereHas('user', static function ($builder) use ($term): void {
                $builder->where('email', 'like', $term);
            });
        }

        return view('admin.registrations', [
            'contextLabel' => null,
            'requests' => $query->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(static fn (Enrollment $row): RegistrationRow => RegistrationRow::from($row)),
            'reviewing' => $this->reviewing($request),
            'cohortOptions' => Options::fromModels(
                Cohort::query()->orderByDesc('start_date')->get(),
                static fn (Cohort $cohort): string => (string) $cohort->getAttribute('name'),
            ),
            'stateOptions' => Options::fromEnum(EnrollmentStatus::class),
            'errorState' => null,
        ]);
    }

    /** The review panel, open on `?review={enrollment}`. */
    private function reviewing(Request $request): ?RegistrationReview
    {
        $id = $request->query('review');

        if (! is_string($id) || $id === '') {
            return null;
        }

        // Pending only. After a decision back() returns to this same URL; the
        // panel used to reopen on the now-decided row with its buttons live,
        // one click away from deciding it again (D-69).
        /** @var Enrollment|null $enrollment */
        $enrollment = Enrollment::query()
            ->awaitingDecision()
            ->with(['user.profile', 'cohort'])
            ->find($id);

        return $enrollment === null ? null : RegistrationReview::from($enrollment);
    }

    /**
     * Approve a request: take a seat, activate the enrolment, tell the person.
     *
     * The request is re-read AS PENDING under a row lock — the cohort locked
     * first, as every seating path does. Before D-69 nothing read the status
     * at all: a second click, a resubmit, or two administrators at once each
     * took another seat and sent another acceptance; approving a withdrawn or
     * completed row re-activated it; and a cohort found full under the lock
     * returned from the closure only, so the acceptance letter went out anyway
     * for a seat nobody got. Now each of those changes nothing and sends
     * nothing, and the letter goes only after a seat was committed.
     */
    public function approve(RegistrationDecisionRequest $request, Enrollment $enrollment): RedirectResponse
    {
        $recipient = $this->recipient($enrollment);

        $outcome = DB::transaction(function () use ($enrollment): string|Cohort {
            $cohort = Cohort::query()
                ->whereKey($enrollment->getAttribute('cohort_id'))
                ->lockForUpdate()
                ->first();

            $locked = Enrollment::query()
                ->whereKey($enrollment->getKey())
                ->awaitingDecision()
                ->lockForUpdate()
                ->first();

            // Decided is reported before full: a decided row in a full cohort
            // was decided, and "no seat" would send the admin to raise a
            // capacity for nothing.
            if ($locked === null) {
                return self::ALREADY_DECIDED;
            }

            if ($cohort === null || $cohort->seatsRemaining() <= 0) {
                return self::NO_FREE_SEAT;
            }

            $this->audit->log('registration.approved', $locked, [
                'status' => EnrollmentStatus::Pending->value,
            ], ['status' => EnrollmentStatus::Active->value]);

            $locked->forceFill([
                'status' => EnrollmentStatus::Active->value,
                'enrolled_at' => Clock::now(),
            ])->save();

            $cohort->forceFill(['seats_taken' => (int) $cohort->seats_taken + 1])->save();

            return $cohort;
        });

        if ($outcome === self::ALREADY_DECIDED) {
            return back()->with('warning', __('admin.registrations.already_decided'));
        }

        if (! $outcome instanceof Cohort) {
            return back()->with('error', __('admin.registrations.no_free_seat'));
        }

        // Only now, with the seat committed: the bell here, the letter queued.
        $this->notifier->notify(
            [(string) $recipient->getKey()],
            'enrollment_approved',
            (string) __('notifications.types.enrollment_approved.title'),
            (string) __('notifications.types.enrollment_approved.body'),
            route('dashboard'),
        );

        EnrollmentApproved::dispatch($recipient, (string) $outcome->getAttribute('name'));

        return back()->with('status', __('admin.registrations.approved'));
    }

    /**
     * Decline a request, with the reason the letter must give.
     *
     * Same guard as approve(): only a request still pending is declined. It
     * used to overwrite any status — an accepted participant could be sent a
     * refusal, and their seat stayed counted (D-69).
     */
    public function reject(RegistrationDecisionRequest $request, Enrollment $enrollment): RedirectResponse
    {
        // The reason travels with the event because the letter must give one:
        // a refusal without one is exactly the shape art. 7 forbids. Checked
        // FIRST now, before anything is written.
        $reason = $request->reason();

        if ($reason === null) {
            // RegistrationDecisionRequest marks reject_reason `required` on this
            // path, so an empty one cannot arrive through validation. Coercing it
            // to '' would post a refusal that explains nothing, which is the one
            // outcome art. 7 rules out — so this fails instead.
            throw new \RuntimeException('Rejection of enrolment '.$enrollment->getKey().' carries no reason.');
        }

        $recipient = $this->recipient($enrollment);

        $decided = DB::transaction(function () use ($enrollment, $reason): bool {
            $locked = Enrollment::query()
                ->whereKey($enrollment->getKey())
                ->awaitingDecision()
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            $this->audit->log('registration.rejected', $locked, [
                'status' => EnrollmentStatus::Pending->value,
            ], [
                'status' => EnrollmentStatus::Withdrawn->value,
                'reason' => $reason,
            ]);

            $locked->forceFill(['status' => EnrollmentStatus::Withdrawn->value])->save();

            return true;
        });

        if (! $decided) {
            return back()->with('warning', __('admin.registrations.already_decided'));
        }

        $this->notifier->notify(
            [(string) $recipient->getKey()],
            'enrollment_rejected',
            (string) __('notifications.types.enrollment_rejected.title'),
            (string) __('notifications.types.enrollment_rejected.body', ['reason' => $reason]),
            null,
        );

        EnrollmentRejected::dispatch(
            $recipient,
            (string) config('athar.program_name'),
            $reason,
        );

        return back()->with('status', __('admin.registrations.rejected'));
    }

    /**
     * The account a decision letter is addressed to.
     *
     * `enrollments.user_id` is a constrained, non-nullable foreign key, so an
     * enrolment without an account is unreachable through the application. If a
     * row is ever orphaned, stopping is right: a decision letter addressed to
     * nobody is a worse outcome than an error an administrator can report.
     */
    private function recipient(Enrollment $enrollment): User
    {
        $user = $enrollment->user;

        if ($user === null) {
            throw new \RuntimeException('Enrolment '.$enrollment->getKey().' has no account attached.');
        }

        return $user;
    }

    /** The waiting list of requests as a CSV. */
    public function export(): Response
    {
        $this->authorize('viewAny', Enrollment::class);

        $rows = Enrollment::query()
            ->with(['user.profile', 'cohort'])
            ->where('status', EnrollmentStatus::Pending->value)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (Enrollment $enrollment): array => [
                (string) ($enrollment->user?->profile?->getAttribute('full_name_ar') ?? ''),
                (string) ($enrollment->user?->getAttribute('email') ?? ''),
                (string) ($enrollment->user?->profile?->getAttribute('phone') ?? ''),
                (string) ($enrollment->cohort?->getAttribute('name') ?? ''),
            ])
            ->values()
            ->all();

        return $this->csvResponse([
            __('admin.registrations.export.name'),
            __('admin.registrations.export.email'),
            __('admin.registrations.export.phone'),
            __('admin.registrations.export.cohort'),
        ], $rows, 'athar-registrations.csv');
    }
}
