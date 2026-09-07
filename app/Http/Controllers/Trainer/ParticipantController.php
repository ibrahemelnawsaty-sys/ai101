<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\AssignmentStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use App\Presenters\Support\Options;
use App\Presenters\Trainer\ParticipantProfile;
use App\Presenters\Trainer\ParticipantRow;
use App\Presenters\Trainer\ParticipantStats;
use App\Presenters\Trainer\ParticipantSubmission;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Services\Journey\JourneyEvaluator;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * The roster of one's own cohort (PRD §8, /trainer/participants).
 *
 * The list is built from the enrolments of the cohort `cohort.scope` resolved,
 * so it cannot reach beyond it — a trainer sees the people they train and
 * nobody else (BR-23). PRD §4.2 grants the full profile of such a participant,
 * which is the panel `?view=` opens.
 *
 * Two deliberate decisions about where each number comes from:
 *
 *  * The TABLE reads the denormalised `enrollments.final_score`, and its
 *    attendance rates come from one batched CertificateEligibility call for the
 *    whole page. Asking ScoreCalculator per row would be fifty participants ×
 *    three queries on every page load, which art. 19 forbids outright.
 *  * The PROFILE panel — one person, opened deliberately — asks the services
 *    themselves, because everything on it decides something about that person:
 *    their rate against BR-26's threshold, their score against BR-11's total,
 *    their journey against BR-21. A figure that decides is never read from a
 *    cache of itself (art. 6).
 *
 * @see BR-11, BR-21, BR-22, BR-23, BR-26 · PRD §4.2, §8 · CONSTITUTION art. 6, art. 19, art. 22
 */
final class ParticipantController extends Controller
{
    use ExportsCsv;
    use ReadsCohortScope;

    private const PER_PAGE = 50;

    /** The Article 17 screen name. */
    private const SCREEN = 'trainer-participants';

    public function __construct(
        private readonly CertificateEligibility $eligibility,
        private readonly ScoreCalculator $scores,
        private readonly JourneyEvaluator $journey,
    ) {}

    public function index(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('trainer.participants', [
                'contextLabel' => null,
                'stats' => ParticipantStats::of(0, 0, null, 0.0, 0),
                'rows' => collect(),
                'selected' => null,
                'stateOptions' => Options::fromEnum(EnrollmentStatus::class),
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $minimum = $this->eligibility->minAttendanceRate($cohort);

        $page = $this->query($request, $cohort)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, Enrollment> $enrollments */
        $enrollments = $page->getCollection();

        $userIds = $enrollments
            ->map(static fn (Enrollment $row): string => (string) $row->getAttribute('user_id'))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        // One rate query for the whole page, from the service that owns the
        // counting rule (art. 6, art. 19).
        $rates = $this->eligibility->attendanceRates($userIds, $cohort);
        $assignmentsCount = $this->publishedAssignmentsCount($cohort);
        $submitted = $this->submittedCounts($cohort, $userIds);

        $rows = $page->through(function (Enrollment $enrollment) use (
            $rates,
            $minimum,
            $assignmentsCount,
            $submitted,
        ): ParticipantRow {
            $userId = (string) $enrollment->getAttribute('user_id');
            $storedScore = $enrollment->getAttribute('final_score');

            return ParticipantRow::from(
                $enrollment,
                $rates[$userId] ?? 0.0,
                $minimum,
                $storedScore === null ? null : (float) $storedScore,
                $submitted[$userId] ?? 0,
                $assignmentsCount,
            );
        });

        return view('trainer.participants', [
            'contextLabel' => $cohort->getAttribute('name'),
            'stats' => $this->stats($cohort, $minimum),
            'rows' => $rows,
            'selected' => $this->selected($request, $cohort, $minimum),
            'stateOptions' => Options::fromEnum(EnrollmentStatus::class),
            'errorState' => null,
            'screen' => self::SCREEN,
            // A cohort nobody has joined yet is empty, and the trainer is told
            // so rather than shown a blank table (Art. 17).
            'screenState' => ScreenState::of($rows->isEmpty()),
        ]);
    }

    /**
     * The roster query, scoped to the cohort the middleware resolved and
     * narrowed by the two filters the screen offers. Neither filter can widen
     * the scope: they are applied on top of it, never instead of it (BR-23).
     *
     * @return Builder<Enrollment>
     */
    private function query(Request $request, Cohort $cohort): Builder
    {
        $query = Enrollment::query()
            ->with('user.profile')
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->orderBy('enrolled_at');

        $state = $request->query('state');

        if (is_string($state) && EnrollmentStatus::tryFrom($state) !== null) {
            $query->where('status', $state);
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
     * The four counters, computed over the WHOLE cohort rather than the page
     * being shown — a counter that changed when the trainer paged would be
     * telling them something untrue.
     */
    private function stats(Cohort $cohort, float $minimum): ParticipantStats
    {
        $base = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value);

        $average = (clone $base)->whereNotNull('attendance_rate')->avg('attendance_rate');

        return ParticipantStats::of(
            total: (clone $base)->count(),
            activeCount: (clone $base)->where('status', EnrollmentStatus::Active->value)->count(),
            averageAttendance: $average === null ? null : (float) $average,
            minimumRate: $minimum,
            atRisk: (clone $base)->where('attendance_rate', '<', $minimum)->count(),
        );
    }

    /** How many published assignments the cohort has — the roster's denominator. */
    private function publishedAssignmentsCount(Cohort $cohort): int
    {
        return Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', AssignmentStatus::Published->value)
            ->count();
    }

    /**
     * How many of those each participant on this page has handed in.
     *
     * Counted DISTINCT by assignment, because BR-19 keeps every version of a
     * submission rather than replacing it: a participant who resubmitted twice
     * has handed in one assignment, not three.
     *
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    private function submittedCounts(Cohort $cohort, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $counts = Submission::query()
            ->whereIn('user_id', $userIds)
            ->whereIn(
                'assignment_id',
                Assignment::query()
                    ->where('cohort_id', $cohort->getKey())
                    ->where('status', AssignmentStatus::Published->value)
                    ->select('id')
            )
            ->selectRaw('user_id, COUNT(DISTINCT assignment_id) as aggregate')
            ->groupBy('user_id')
            ->get();

        $map = [];

        foreach ($counts as $row) {
            $map[(string) $row->getAttribute('user_id')] = (int) $row->getAttribute('aggregate');
        }

        return $map;
    }

    /**
     * The full profile panel, open on `?view={participant}`.
     *
     * The id is looked up INSIDE the cohort's enrolments, so changing it in the
     * address bar reaches nothing: an id from another cohort simply finds no
     * enrolment and the panel stays shut (BR-23, art. 22).
     */
    private function selected(Request $request, Cohort $cohort, float $minimum): ?ParticipantProfile
    {
        $id = $request->query('view');

        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var Enrollment|null $enrollment */
        $enrollment = Enrollment::query()
            ->with('user.profile')
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->where('user_id', $id)
            ->first();

        $participant = $enrollment?->user;

        if ($enrollment === null || ! $participant instanceof User) {
            return null;
        }

        $counts = $this->eligibility->sessionCounts($participant, $cohort);
        $overview = $this->journey->overview($participant, $cohort);
        $current = $overview['current'];

        return ParticipantProfile::of(
            participant: $participant,
            enrollment: $enrollment,
            ratePercent: $this->eligibility->attendanceRate($participant, $cohort),
            minimumRate: $minimum,
            attendedSessions: (int) $counts['attended'],
            totalSessions: (int) $counts['total'],
            journeyPercent: (float) $overview['percent'],
            currentStepTitle: $current === null
                ? (string) __('journey.status.completed')
                : (string) $current->getAttribute('title'),
            score: $this->scores->finalScore($participant, $cohort),
            passScore: $this->scores->passScore($cohort),
            submissions: $this->submissionsOf($participant, $cohort),
        );
    }

    /**
     * Every published assignment of the cohort, paired with this participant's
     * latest submission for it when there is one.
     *
     * @return Collection<int, ParticipantSubmission>
     */
    private function submissionsOf(User $participant, Cohort $cohort): Collection
    {
        $assignments = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', AssignmentStatus::Published->value)
            ->orderBy('due_at')
            ->get();

        $submissions = Submission::query()
            ->with('latestEvaluation')
            ->where('user_id', $participant->getKey())
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->orderByDesc('version')
            ->get()
            ->keyBy(static fn (Submission $row): string => (string) $row->getAttribute('assignment_id'));

        return $assignments
            ->map(static fn (Assignment $assignment): ParticipantSubmission => ParticipantSubmission::of(
                $assignment,
                $submissions->get((string) $assignment->getKey()),
            ))
            ->values();
    }

    /** The same roster as a CSV, with the same scope (BR-23). */
    public function export(Request $request): Response
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->authorize('export', $cohort);

        $rows = $this->query($request, $cohort)
            ->get()
            ->map(static fn (Enrollment $enrollment): array => [
                (string) ($enrollment->user?->profile?->getAttribute('full_name_ar') ?? ''),
                (string) ($enrollment->user?->getAttribute('email') ?? ''),
                (string) ($enrollment->user?->profile?->getAttribute('phone') ?? ''),
                (string) ($enrollment->getAttribute('status')?->value ?? ''),
            ])
            ->values()
            ->all();

        return $this->csvResponse([
            __('trainer.participants.export.name'),
            __('trainer.participants.export.email'),
            __('trainer.participants.export.phone'),
            __('trainer.participants.export.status'),
        ], $rows, 'athar-participants.csv');
    }
}
