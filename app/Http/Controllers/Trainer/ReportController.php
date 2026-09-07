<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\AssignmentStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Session;
use App\Models\Submission;
use App\Models\User;
use App\Models\Week;
use App\Presenters\Shared\ChartPoint;
use App\Presenters\Support\Options;
use App\Presenters\Trainer\AtRiskPerson;
use App\Presenters\Trainer\ReportSummary;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Services\Permissions\RoleResolver;
use App\Services\Time\Clock;
use App\Support\AttendanceCounting;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Cohort reports for the trainer (PRD §8, /trainer/reports).
 *
 * Every figure here is computed by the service that owns it — attendance rates
 * by CertificateEligibility (batched, one query for the whole cohort), scores
 * by ScoreCalculator — never recomputed in a controller, so the report and the
 * certificate can never disagree (art. 6).
 *
 * The whole page is bound to the cohort `cohort.scope` resolved. The cohort
 * filter on the toolbar posts back through that same middleware, which refuses
 * a cohort this trainer is not assigned to with 403 and an audit entry, so the
 * options list here is a convenience and never the permission (BR-23).
 *
 * The bars are ChartPoints, not a charting library: a label, a percentage and
 * the variant that percentage earns, drawn by a plain progress bar that fills
 * from the right and prints its own number (art. 16, art. 18).
 *
 * @see BR-11, BR-23, BR-26 · PRD §4.2, §8, §9.9.6, §9.15 · CONSTITUTION art. 6, art. 18, art. 19, art. 22
 */
final class ReportController extends Controller
{
    use ExportsCsv;
    use ReadsCohortScope;

    /** How many participants the at-risk sweep looks at (art. 19). */
    private const AT_RISK_LIMIT = 300;

    public function __construct(
        private readonly ScoreCalculator $scores,
        private readonly CertificateEligibility $eligibility,
        private readonly RoleResolver $roles,
    ) {}

    public function index(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('trainer.reports', [
                'contextLabel' => null,
                'summary' => ReportSummary::of(0, null, null, 0.0, 0.0, 0.0),
                'attendanceBySession' => collect(),
                'submissionsByAssignment' => collect(),
                'atRisk' => collect(),
                'cohortOptions' => $this->cohortOptions($request),
                'weekOptions' => [],
                'errorState' => null,
            ]);
        }

        $this->authorize('viewReports', $cohort);

        $minimum = $this->eligibility->minAttendanceRate($cohort);
        $week = $this->selectedWeekId($request, $cohort);

        $participants = $this->participants($cohort);

        // One rate query for the whole cohort, from the service that owns the
        // counting rule — never a loop of per-person calls (art. 6, art. 19).
        $rates = $this->eligibility->attendanceRates(
            array_map(static fn (mixed $id): string => (string) $id, $participants->modelKeys()),
            $cohort,
        );

        $sessions = $this->sessions($cohort, $week);
        $assignments = $this->assignments($cohort, $week);
        $submitters = $this->submitterCounts($assignments->modelKeys());

        return view('trainer.reports', [
            'contextLabel' => $cohort->getAttribute('name'),
            'summary' => $this->summary($cohort, $participants, $rates, $assignments, $submitters, $minimum),
            'attendanceBySession' => $this->attendanceBySession($sessions, $participants->count(), $minimum),
            'submissionsByAssignment' => $this->submissionsByAssignment($assignments, $submitters, $participants->count()),
            'atRisk' => $this->atRisk($cohort, $participants, $rates, $minimum),
            'cohortOptions' => $this->cohortOptions($request),
            'weekOptions' => Options::fromModels(
                Week::query()->where('cohort_id', $cohort->getKey())->orderBy('index')->get(),
                static fn (Week $item): string => (string) $item->getAttribute('title'),
            ),
            'errorState' => null,
        ]);
    }

    /**
     * The cohorts this account may report on.
     *
     * An administrator reaches every cohort (PRD §4.2); a trainer reaches the
     * ones they are enrolled on as a trainer, which is the same list
     * `cohort.scope` enforces. The list is built from that authority rather
     * than from the query string.
     *
     * @return list<array{value: string, label: string}>
     */
    private function cohortOptions(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        $query = Cohort::query()->with('program')->orderByDesc('start_date');

        if (! $this->roles->isAdmin($user)) {
            $query->whereIn('id', $this->roles->trainerCohortIds($user));
        }

        return Options::fromModels(
            $query->get(),
            static fn (Cohort $item): string => (string) $item->getAttribute('name'),
        );
    }

    /**
     * The week the toolbar filter names, validated against the cohort's own
     * weeks so a week id from elsewhere narrows nothing (BR-23).
     */
    private function selectedWeekId(Request $request, Cohort $cohort): ?string
    {
        $week = $request->query('week');

        if (! is_string($week) || $week === '') {
            return null;
        }

        $exists = Week::query()
            ->where('cohort_id', $cohort->getKey())
            ->whereKey($week)
            ->exists();

        return $exists ? $week : null;
    }

    /**
     * Active participants of the cohort.
     *
     * @return Collection<int, User>
     */
    private function participants(Cohort $cohort): Collection
    {
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
            ->limit(self::AT_RISK_LIMIT)
            ->get();
    }

    /**
     * Finished, non-cancelled sessions carrying their attended-count.
     *
     * @return Collection<int, Session>
     */
    private function sessions(Cohort $cohort, ?string $weekId): Collection
    {
        $query = Session::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->withCount([
                'attendances as attended_count' => static fn (Builder $query): Builder => $query->whereIn(
                    'status',
                    AttendanceCounting::countedAsAttendedValues()
                ),
            ])
            ->orderBy('date')
            ->orderBy('start_time');

        if ($weekId !== null) {
            $query->where('week_id', $weekId);
        }

        return $query->get();
    }

    /**
     * Published assignments, in due order.
     *
     * @return Collection<int, Assignment>
     */
    private function assignments(Cohort $cohort, ?string $weekId): Collection
    {
        $query = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', AssignmentStatus::Published->value)
            ->orderBy('due_at');

        if ($weekId !== null) {
            $query->where('week_id', $weekId);
        }

        return $query->get();
    }

    /**
     * How many DISTINCT participants handed each assignment in.
     *
     * Deliberately one aggregate query rather than `withCount`: BR-19 keeps
     * every version of a submission instead of replacing it, so a plain row
     * count would report a participant who resubmitted twice as three
     * hand-ins, and `withCount` cannot express COUNT(DISTINCT user_id).
     *
     * @param  array<int, mixed>  $assignmentIds
     * @return array<string, int>
     */
    private function submitterCounts(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        $rows = Submission::query()
            ->whereIn('assignment_id', $assignmentIds)
            ->selectRaw('assignment_id, COUNT(DISTINCT user_id) as aggregate')
            ->groupBy('assignment_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('assignment_id')] = (int) $row->getAttribute('aggregate');
        }

        return $counts;
    }

    /**
     * @param  Collection<int, User>  $participants
     * @param  array<string, float>  $rates
     * @param  Collection<int, Assignment>  $assignments
     * @param  array<string, int>  $submitters
     */
    private function summary(
        Cohort $cohort,
        Collection $participants,
        array $rates,
        Collection $assignments,
        array $submitters,
        float $minimum,
    ): ReportSummary {
        $count = $participants->count();

        $averageRate = $rates === [] ? null : array_sum($rates) / count($rates);

        $storedScore = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->whereNotNull('final_score')
            ->avg('final_score');

        // Expected hand-ins across the cohort, against what actually arrived.
        $expected = $count * $assignments->count();
        $received = $assignments->sum(
            static fn (Assignment $item): int => $submitters[(string) $item->getKey()] ?? 0
        );

        return ReportSummary::of(
            participants: $count,
            averageAttendance: $averageRate,
            averageScore: $storedScore === null ? null : (float) $storedScore,
            submissionRate: $expected === 0 ? 0.0 : ($received / $expected) * 100,
            completionRate: $this->completionRate($cohort),
            minimumRate: $minimum,
        );
    }

    /**
     * How far through its own calendar the cohort is, from the server clock and
     * never the browser's (BR-07). Before the start date it is zero; after the
     * end date it is one hundred.
     */
    private function completionRate(Cohort $cohort): float
    {
        $start = $cohort->getAttribute('start_date');
        $end = $cohort->getAttribute('end_date');

        if ($start === null || $end === null) {
            return 0.0;
        }

        $from = Clock::toUtc($start)->getTimestamp();
        $to = Clock::toUtc($end)->getTimestamp();
        $now = Clock::now()->getTimestamp();

        if ($to <= $from) {
            return $now >= $to ? 100.0 : 0.0;
        }

        return max(0.0, min(100.0, (($now - $from) / ($to - $from)) * 100));
    }

    /**
     * @param  Collection<int, Session>  $sessions
     * @return Collection<int, ChartPoint>
     */
    private function attendanceBySession(Collection $sessions, int $participants, float $minimum): Collection
    {
        if ($participants === 0) {
            return collect();
        }

        return $sessions
            ->map(static fn (Session $session): ChartPoint => ChartPoint::rate(
                (string) ($session->getAttribute('topic') ?? $session->getAttribute('title') ?? ''),
                ((int) ($session->getAttribute('attended_count') ?? 0) / $participants) * 100,
                $minimum,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, Assignment>  $assignments
     * @param  array<string, int>  $submitters
     * @return Collection<int, ChartPoint>
     */
    private function submissionsByAssignment(Collection $assignments, array $submitters, int $participants): Collection
    {
        if ($participants === 0) {
            return collect();
        }

        return $assignments
            ->map(static fn (Assignment $assignment): ChartPoint => ChartPoint::rate(
                (string) $assignment->getAttribute('title'),
                (($submitters[(string) $assignment->getKey()] ?? 0) / $participants) * 100,
            ))
            ->values();
    }

    /**
     * Participants under the cohort's minimum attendance rate (BR-26).
     *
     * The reasons come from CertificateEligibility itself, whole: BR-26 needs
     * both conditions met and the trainer needs to see which one is missing.
     *
     * @param  Collection<int, User>  $participants
     * @param  array<string, float>  $rates
     * @return Collection<int, AtRiskPerson>
     */
    private function atRisk(Cohort $cohort, Collection $participants, array $rates, float $minimum): Collection
    {
        return $participants
            ->filter(static fn (User $user): bool => ($rates[(string) $user->getKey()] ?? 0.0) < $minimum)
            ->map(fn (User $user): AtRiskPerson => AtRiskPerson::of(
                $user,
                $rates[(string) $user->getKey()] ?? 0.0,
                $minimum,
                $this->scores->finalScore($user, $cohort),
                $this->eligibility->reasons($user, $cohort),
            ))
            ->values();
    }

    /**
     * The same rows as a CSV. Same scope, same services, same numbers: an
     * export that recomputed anything of its own would eventually disagree
     * with the screen it came from (art. 6).
     */
    public function export(Request $request): Response
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->authorize('export', $cohort);

        $rows = Enrollment::query()
            ->with('user.profile')
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->get()
            ->map(function (Enrollment $enrollment) use ($cohort): array {
                $user = $enrollment->user;

                if (! $user instanceof User) {
                    return ['', '', '', ''];
                }

                return [
                    (string) ($user->profile?->getAttribute('full_name_ar') ?? ''),
                    number_format($this->eligibility->attendanceRate($user, $cohort), 2, '.', ''),
                    number_format($this->scores->finalScore($user, $cohort), 2, '.', ''),
                    $this->eligibility->isEligible($user, $cohort) ? '1' : '0',
                ];
            })
            ->values()
            ->all();

        return $this->csvResponse([
            __('trainer.reports.export.participant'),
            __('trainer.reports.export.attendance'),
            __('trainer.reports.export.score'),
            __('trainer.reports.export.eligible'),
        ], $rows, 'athar-cohort-report.csv');
    }
}
