<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Enrollment;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Session;
use App\Models\Submission;
use App\Presenters\Shared\TotalsWarning;
use App\Presenters\Trainer\ParticipantStats;
use App\Presenters\Trainer\SessionRow;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The trainer's own information dashboard (PR-5, batch 2).
 *
 * Neither the trainer nor the coordinator had a stats home before this — the
 * shared `/dashboard` route sent a trainer straight to the submissions queue,
 * skipping any overview at all. This screen answers the trainer's own
 * recurring questions: what session is next, how much grading is still
 * waiting (weekly tasks and final-project settings moved to admin-authored —
 * D-110, D-111 — but the trainer still grades both), and whether the
 * cohort's attendance is somewhere it should worry about.
 *
 * `ParticipantStats` is the same presenter Trainer\ParticipantController
 * already built for BR-26's own counters — reused here rather than
 * re-derived, so the two screens can never disagree about what "at risk"
 * means (art. 6).
 *
 * @see BR-11, BR-26 · CONSTITUTION Art. 5, Art. 6, Art. 17
 */
final class DashboardController extends Controller
{
    use ReadsCohortScope;

    /** The Article 17 screen name. */
    private const SCREEN = 'trainer-dashboard';

    public function __construct(
        private readonly ScoreCalculator $scores,
        private readonly CertificateEligibility $eligibility,
        private readonly AttendanceWindow $window,
    ) {}

    public function __invoke(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('trainer.dashboard', [
                'contextLabel' => null,
                'stats' => ParticipantStats::of(0, 0, null, 0.0, 0),
                'nextSession' => null,
                'totalsWarning' => null,
                'ungradedWeeklyTasks' => 0,
                'ungradedFinalProject' => 0,
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $cohortId = (string) $cohort->getKey();
        $minimum = $this->eligibility->minAttendanceRate($cohort);

        $enrollments = Enrollment::query()
            ->where('cohort_id', $cohortId)
            ->where('role_in_cohort', EnrollmentRole::Participant->value);

        $averageAttendance = (clone $enrollments)->whereNotNull('attendance_rate')->avg('attendance_rate');

        $stats = ParticipantStats::of(
            total: (clone $enrollments)->count(),
            activeCount: (clone $enrollments)->where('status', EnrollmentStatus::Active->value)->count(),
            averageAttendance: $averageAttendance === null ? null : (float) $averageAttendance,
            minimumRate: $minimum,
            atRisk: (clone $enrollments)->where('attendance_rate', '<', $minimum)->count(),
        );

        $session = Session::query()
            ->with('trainer.profile')
            ->where('cohort_id', $cohortId)
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereDate('date', '>=', Clock::toRiyadh(Clock::now())->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->first();

        $assignmentIds = Assignment::query()->where('cohort_id', $cohortId)->pluck('id');
        $finalProjectIds = FinalProject::query()->where('cohort_id', $cohortId)->pluck('id');

        return view('trainer.dashboard', [
            'contextLabel' => $cohort->getAttribute('name'),
            'stats' => $stats,
            'nextSession' => $session === null ? null : SessionRow::from($session, $this->window),
            // BR-11 — a mismatch warns, never blocks (PROJECT-CONTRACT §7).
            'totalsWarning' => TotalsWarning::forCohort($this->scores, $cohort),
            'ungradedWeeklyTasks' => Submission::query()
                ->whereIn('assignment_id', $assignmentIds)
                ->whereDoesntHave('evaluations')
                ->count(),
            'ungradedFinalProject' => ProjectSubmission::query()
                ->whereIn('final_project_id', $finalProjectIds)
                ->whereDoesntHave('evaluations')
                ->count(),
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::NORMAL,
        ]);
    }
}
