<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Events\EnrollmentRejected;
use App\Events\EnrollmentApproved;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegistrationDecisionRequest;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Presenters\Admin\RegistrationReview;
use App\Presenters\Admin\RegistrationRow;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
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

    public function __construct(private readonly AuditLogger $audit) {}

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

        /** @var Enrollment|null $enrollment */
        $enrollment = Enrollment::query()
            ->with(['user.profile', 'cohort'])
            ->find($id);

        return $enrollment === null ? null : RegistrationReview::from($enrollment);
    }

    public function approve(RegistrationDecisionRequest $request, Enrollment $enrollment): RedirectResponse
    {
        DB::transaction(function () use ($enrollment): void {
            $cohort = $enrollment->cohort()->lockForUpdate()->first();

            if ($cohort === null || $cohort->seatsRemaining() <= 0) {
                return;
            }

            $this->audit->log('registration.approved', $enrollment, [
                'status' => $enrollment->getAttribute('status')?->value,
            ], ['status' => EnrollmentStatus::Active->value]);

            $enrollment->forceFill([
                'status' => EnrollmentStatus::Active->value,
                'enrolled_at' => Clock::now(),
            ])->save();

            $cohort->forceFill(['seats_taken' => (int) $cohort->seats_taken + 1])->save();
        });

        // Announced after the row is saved, never before: a letter about a
        // place that failed to save is a promise the platform cannot keep.
        EnrollmentApproved::dispatch(
            $enrollment->user,
            (string) $enrollment->cohort?->getAttribute('name'),
        );

        return back()->with('status', __('admin.registrations.approved'));
    }

    public function reject(RegistrationDecisionRequest $request, Enrollment $enrollment): RedirectResponse
    {
        $this->audit->log('registration.rejected', $enrollment, [
            'status' => $enrollment->getAttribute('status')?->value,
        ], [
            'status' => EnrollmentStatus::Withdrawn->value,
            'reason' => $request->reason(),
        ]);

        $enrollment->forceFill(['status' => EnrollmentStatus::Withdrawn->value])->save();

        // The reason travels with the event because the letter must give one:
        // a refusal without one is exactly the shape art. 7 forbids.
        EnrollmentRejected::dispatch(
            $enrollment->user,
            (string) config('athar.program_name'),
            $request->reason(),
        );

        return back()->with('status', __('admin.registrations.rejected'));
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
