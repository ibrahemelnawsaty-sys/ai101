<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use App\Presenters\Admin\CertificateCandidate;
use App\Presenters\Admin\DashboardStats;
use App\Presenters\Admin\RegistrationRow;
use App\Presenters\Admin\UngradedGroup;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * The administration console home (PRD §9.18).
 *
 * Six figures and three queues: the registrations waiting for a decision, the
 * submissions waiting for a grade, and the participants who have met both
 * certificate conditions but hold no certificate yet.
 *
 * The last of those three is asked of CertificateEligibility, because BR-26 is
 * that service's to answer and nowhere else's — a console that computed its own
 * version of "eligible" would eventually disagree with the certificate itself.
 *
 * Everything the screen prints is shaped by a presenter before it leaves this
 * method; the template holds no logic of its own (art. 5, art. 6).
 *
 * @see BR-26, BR-27, BR-31 · PRD §9.18 · CONSTITUTION Art. 5, Art. 6, Art. 17
 */
final class DashboardController extends Controller
{
    /** How many rows each attention queue shows. */
    private const QUEUE_LIMIT = 10;

    /** The Article 17 screen name. */
    private const SCREEN = 'admin-dashboard';

    public function __construct(
        private readonly CertificateEligibility $eligibility,
        private readonly ScoreCalculator $scores,
    ) {}

    public function __invoke(): View
    {
        $this->authorize('viewAny', User::class);

        $cohort = $this->currentCohort();

        return view('admin.dashboard', [
            'contextLabel' => $cohort?->getAttribute('name'),
            'stats' => $this->stats(),
            'pendingRegistrations' => Enrollment::query()
                ->with(['user.profile', 'cohort'])
                ->where('status', EnrollmentStatus::Pending->value)
                ->orderBy('created_at')
                ->limit(self::QUEUE_LIMIT)
                ->get()
                ->map(static fn (Enrollment $row): RegistrationRow => RegistrationRow::from($row)),
            'ungraded' => $this->ungraded(),
            'readyForCertificate' => $this->readyForCertificate($cohort),
            'errorState' => null,
            'screen' => self::SCREEN,
            // The six figures are always there to print, even when every one of
            // them is zero, so this console home is never the empty state.
            'screenState' => ScreenState::NORMAL,
        ]);
    }

    /**
     * The six figures PRD §9.18 names.
     *
     * The two averages read the denormalised `enrollments` columns rather than
     * asking a service per participant: this is a platform-wide headline, and a
     * per-row calculation over every enrolment would breach the query budget
     * (art. 19). Any figure that decides something about one person — whether
     * they passed, whether they qualify — is asked of its service on the screen
     * that shows it.
     */
    private function stats(): DashboardStats
    {
        $attendance = Enrollment::query()->whereNotNull('attendance_rate')->avg('attendance_rate');
        $score = Enrollment::query()->whereNotNull('final_score')->avg('final_score');

        return DashboardStats::of(
            totalRegistered: User::query()->count(),
            activeUsers: User::query()->where('status', UserStatus::Active->value)->count(),
            averageAttendance: $attendance === null ? null : (float) $attendance,
            averageScore: $score === null ? null : (float) $score,
            ungradedSubmissions: Submission::query()->whereDoesntHave('evaluations')->count(),
            certificatesIssued: Certificate::query()->whereNull('revoked_at')->count(),
        );
    }

    /**
     * Ungraded submissions, grouped by the cohort they belong to.
     *
     * @return Collection<int, UngradedGroup>
     */
    private function ungraded(): Collection
    {
        return Submission::query()
            ->with(['assignment.cohort'])
            ->whereDoesntHave('evaluations')
            ->orderBy('submitted_at')
            ->limit(200)
            ->get()
            ->groupBy(static fn (Submission $row): string => (string) ($row->assignment?->cohort?->getAttribute('name') ?? ''))
            ->map(static fn (Collection $rows, string $name): UngradedGroup => UngradedGroup::of($name, $rows->count()))
            ->values()
            ->take(self::QUEUE_LIMIT);
    }

    /**
     * Participants of the current cohort who have met both conditions and hold
     * no certificate yet. The list is capped: this is a queue to work through,
     * not a report (Art. 19).
     *
     * @return Collection<int, CertificateCandidate>
     */
    private function readyForCertificate(?Cohort $cohort): Collection
    {
        if ($cohort === null) {
            return collect();
        }

        $holders = Certificate::query()
            ->where('cohort_id', $cohort->getKey())
            ->whereNull('revoked_at')
            ->pluck('user_id')
            ->all();

        return User::query()
            ->with('profile')
            ->whereIn(
                'id',
                Enrollment::query()
                    ->where('cohort_id', $cohort->getKey())
                    ->where('role_in_cohort', EnrollmentRole::Participant->value)
                    ->where('status', EnrollmentStatus::Active->value)
                    ->select('user_id')
            )
            ->whereNotIn('id', $holders)
            ->limit(100)
            ->get()
            ->filter(fn (User $user): bool => $this->eligibility->isEligible($user, $cohort))
            ->take(self::QUEUE_LIMIT)
            ->map(fn (User $user): CertificateCandidate => CertificateCandidate::from(
                $user,
                $cohort,
                $this->eligibility,
                $this->scores,
            ))
            ->values();
    }

    private function currentCohort(): ?Cohort
    {
        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()
            ->whereIn('status', [CohortStatus::Running->value, CohortStatus::Open->value, CohortStatus::Upcoming->value])
            ->orderByDesc('start_date')
            ->first();

        return $cohort;
    }
}
