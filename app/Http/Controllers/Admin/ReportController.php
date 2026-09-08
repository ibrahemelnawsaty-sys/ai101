<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Presenters\Admin\ReportCohortRow;
use App\Presenters\Admin\ReportSummary;
use App\Presenters\Shared\ChartPoint;
use App\Presenters\Support\Options;
use App\Support\Dates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Platform-wide reports (PRD §9.18).
 *
 * An administrator reads every cohort, which is exactly why this screen exists
 * separately from the trainer's: the trainer's report is scoped to their own
 * cohorts and must stay that way (BR-23).
 *
 * The figures here are counts, not judgements. Anything that decides whether a
 * person passed or qualified is asked of the services that own that decision,
 * on the screens that show it (Art. 6).
 *
 * Every aggregate is one grouped query keyed by cohort, never a loop that asks
 * per row: a report is the screen most likely to turn into an N+1 (Art. 19).
 *
 * @see BR-22, BR-23 · PRD §4.2, §9.18 · CONSTITUTION Art. 6, Art. 19, Art. 22
 */
final class ReportController extends Controller
{
    use ExportsCsv;

    private const PER_PAGE = 25;

    /** How many buckets the registrations trend shows. */
    private const TREND_BUCKETS = 8;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Cohort::class);

        $cohorts = Cohort::query()
            ->with('program')
            ->withCount(['enrollments', 'sessions', 'certificates'])
            ->orderByDesc('start_date')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $ids = $cohorts->getCollection()->modelKeys();

        $participants = $this->participantCounts($ids);
        $completed = $this->completedCounts($ids);
        $attendance = $this->averageAttendance($ids);
        $scores = $this->averageScores($ids);
        $expected = $this->expectedSubmissions($ids, $participants);
        $submitted = $this->submissionCounts($ids);

        // Built BEFORE through(), which replaces the paginator's collection in
        // place: after it, the paginator holds presenters, not cohorts.
        $attendanceByCohort = $cohorts->getCollection()
            ->map(static fn (Cohort $cohort): ChartPoint => ChartPoint::rate(
                (string) $cohort->getAttribute('name'),
                (float) ($attendance[(string) $cohort->getKey()] ?? 0.0),
                (float) $cohort->getAttribute('min_attendance_rate'),
            ))
            ->values();

        $rows = $cohorts->through(static function (Cohort $cohort) use (
            $participants,
            $completed,
            $attendance,
            $scores,
            $expected,
            $submitted,
        ): ReportCohortRow {
            $key = (string) $cohort->getKey();
            $people = (int) ($participants[$key] ?? 0);

            return ReportCohortRow::from(
                cohort: $cohort,
                participants: $people,
                averageAttendance: $attendance[$key] ?? null,
                averageScore: $scores[$key] ?? null,
                submissionRate: self::ratio((float) ($submitted[$key] ?? 0), (float) ($expected[$key] ?? 0)),
                completionRate: self::ratio((float) ($completed[$key] ?? 0), (float) $people),
            );
        });

        return view('admin.reports', [
            'contextLabel' => null,
            'summary' => $this->summary($participants, $completed, $attendance, $scores, $expected, $submitted),
            'registrationsOverTime' => $this->registrationsOverTime($request),
            'attendanceByCohort' => $attendanceByCohort,
            'cohortRows' => $rows,
            'cohortOptions' => Options::fromModels(
                Cohort::query()->orderByDesc('start_date')->get(),
                static fn (Cohort $cohort): string => (string) $cohort->getAttribute('name'),
            ),
            'errorState' => null,
        ]);
    }

    /**
     * @param  array<string, int>  $participants
     * @param  array<string, int>  $completed
     * @param  array<string, float>  $attendance
     * @param  array<string, float>  $scores
     * @param  array<string, int>  $expected
     * @param  array<string, int>  $submitted
     */
    private function summary(
        array $participants,
        array $completed,
        array $attendance,
        array $scores,
        array $expected,
        array $submitted,
    ): ReportSummary {
        $attendanceValues = array_values($attendance);
        $scoreValues = array_values($scores);

        return ReportSummary::of(
            registrations: Enrollment::query()->count(),
            averageAttendance: $attendanceValues === [] ? null : array_sum($attendanceValues) / count($attendanceValues),
            averageScore: $scoreValues === [] ? null : array_sum($scoreValues) / count($scoreValues),
            submissionRate: self::ratio((float) array_sum($submitted), (float) array_sum($expected)),
            completionRate: self::ratio((float) array_sum($completed), (float) array_sum($participants)),
        );
    }

    /**
     * New enrolments per week, most recent last.
     *
     * Grouped in PHP rather than with a database date function, so the same
     * code answers on MySQL and on the SQLite the test suite runs against.
     *
     * @return Collection<int, ChartPoint>
     */
    private function registrationsOverTime(Request $request): Collection
    {
        $query = Enrollment::query()->orderBy('created_at');

        $from = $request->query('from');

        if (is_string($from) && $from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        $to = $request->query('to');

        if (is_string($to) && $to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        $buckets = $query
            ->limit(5000)
            ->get(['created_at'])
            ->groupBy(static fn (Enrollment $row): string => Dates::shortDate($row->getAttribute('created_at')))
            ->map(static fn (Collection $rows): int => $rows->count())
            ->take(-self::TREND_BUCKETS);

        $max = $buckets->max() ?? 0;

        return $buckets
            ->map(static fn (int $count, string $label): ChartPoint => ChartPoint::count($label, $count, (int) $max))
            ->values();
    }

    /**
     * @param  array<int, mixed>  $cohortIds
     * @return array<string, int>
     */
    private function participantCounts(array $cohortIds): array
    {
        return $this->keyed(
            Enrollment::query()
                ->whereIn('cohort_id', $cohortIds)
                ->where('role_in_cohort', EnrollmentRole::Participant->value)
                ->groupBy('cohort_id')
                ->selectRaw('cohort_id, COUNT(*) as aggregate')
                ->get(),
        );
    }

    /**
     * @param  array<int, mixed>  $cohortIds
     * @return array<string, int>
     */
    private function completedCounts(array $cohortIds): array
    {
        return $this->keyed(
            Enrollment::query()
                ->whereIn('cohort_id', $cohortIds)
                ->where('role_in_cohort', EnrollmentRole::Participant->value)
                ->where('status', EnrollmentStatus::Completed->value)
                ->groupBy('cohort_id')
                ->selectRaw('cohort_id, COUNT(*) as aggregate')
                ->get(),
        );
    }

    /**
     * @param  array<int, mixed>  $cohortIds
     * @return array<string, float>
     */
    private function averageAttendance(array $cohortIds): array
    {
        $rows = Enrollment::query()
            ->whereIn('cohort_id', $cohortIds)
            ->whereNotNull('attendance_rate')
            ->groupBy('cohort_id')
            ->selectRaw('cohort_id, AVG(attendance_rate) as aggregate')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->getAttribute('cohort_id')] = (float) $row->getAttribute('aggregate');
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $cohortIds
     * @return array<string, float>
     */
    private function averageScores(array $cohortIds): array
    {
        $rows = Enrollment::query()
            ->whereIn('cohort_id', $cohortIds)
            ->whereNotNull('final_score')
            ->groupBy('cohort_id')
            ->selectRaw('cohort_id, AVG(final_score) as aggregate')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->getAttribute('cohort_id')] = (float) $row->getAttribute('aggregate');
        }

        return $map;
    }

    /**
     * How many hand-ins a cohort should have: published assignments × people.
     *
     * @param  array<int, mixed>  $cohortIds
     * @param  array<string, int>  $participants
     * @return array<string, int>
     */
    private function expectedSubmissions(array $cohortIds, array $participants): array
    {
        $assignments = $this->keyed(
            Assignment::query()
                ->whereIn('cohort_id', $cohortIds)
                ->where('status', AssignmentStatus::Published->value)
                ->groupBy('cohort_id')
                ->selectRaw('cohort_id, COUNT(*) as aggregate')
                ->get(),
        );

        $expected = [];

        foreach ($assignments as $cohortId => $count) {
            $expected[$cohortId] = $count * (int) ($participants[$cohortId] ?? 0);
        }

        return $expected;
    }

    /**
     * @param  array<int, mixed>  $cohortIds
     * @return array<string, int>
     */
    private function submissionCounts(array $cohortIds): array
    {
        $rows = Submission::query()
            ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
            ->whereIn('assignments.cohort_id', $cohortIds)
            ->groupBy('assignments.cohort_id')
            ->selectRaw('assignments.cohort_id as cohort_id, COUNT(*) as aggregate')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->getAttribute('cohort_id')] = (int) $row->getAttribute('aggregate');
        }

        return $map;
    }

    /**
     * Generic in the row model: callers hand in a concrete result set
     * (Collection<int, Enrollment>, Collection<int, Assignment>), and
     * Collection's generics are invariant, so a plain Model parameter would
     * reject every one of them.
     *
     * @template TRow of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TRow>  $rows
     * @return array<string, int>
     */
    private function keyed(Collection $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->getAttribute('cohort_id')] = (int) $row->getAttribute('aggregate');
        }

        return $map;
    }

    /** A percentage that never divides by zero and never exceeds 100. */
    private static function ratio(float $part, float $whole): float
    {
        if ($whole <= 0.0) {
            return 0.0;
        }

        return min(100.0, $part / $whole * 100);
    }

    /** The cohort table as a CSV, for whoever wants it in a spreadsheet. */
    public function export(): Response
    {
        $this->authorize('viewAny', Cohort::class);

        $rows = Cohort::query()
            ->with('program')
            ->withCount(['enrollments', 'sessions', 'certificates'])
            ->orderByDesc('start_date')
            ->get()
            ->map(static fn (Cohort $cohort): array => [
                (string) ($cohort->program?->getAttribute('name_ar') ?? ''),
                (string) $cohort->getAttribute('name'),
                (string) $cohort->getAttribute('status')?->value,
                (string) $cohort->getAttribute('enrollments_count'),
                (string) $cohort->getAttribute('sessions_count'),
                (string) $cohort->getAttribute('certificates_count'),
            ])
            ->values()
            ->all();

        return $this->csvResponse([
            __('admin.reports.export.program'),
            __('admin.reports.export.cohort'),
            __('admin.reports.export.status'),
            __('admin.reports.export.participants'),
            __('admin.reports.export.sessions'),
            __('admin.reports.export.certificates'),
        ], $rows, 'athar-reports.csv');
    }
}
