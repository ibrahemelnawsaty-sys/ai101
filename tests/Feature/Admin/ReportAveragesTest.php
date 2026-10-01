<?php

declare(strict_types=1);

/**
 * Phase 5 · D-150 — the averages on the reports and the dashboard are the same numbers the
 * certificates screen shows, worked out when the page is drawn.
 *
 * They averaged two columns of `enrollments` that nothing keeps current, so every cohort read
 * «—» while its certificates screen showed 33% to 100% attendance and 12 to 74 marks out of 100.
 * The owner chose option A: ask the services that own the two figures — `CertificateEligibility`
 * for attendance, `ScoreCalculator` for the mark — and average what they say. Neither service's
 * rule is touched; a stale column can no longer put a number on the screen.
 *
 * What an average is over (stated, not assumed silently): the cohort's participants who are
 * enrolled — active or completed, the same two states the certificate check accepts — never
 * trainers, coordinators or people who withdrew. A cohort with nothing yet to measure (no
 * session has ended / nothing has been marked) has no average: «—», never «0».
 *
 * @see BR-11, BR-22, BR-26 · PRD §9.18 · CONSTITUTION art. 6, art. 19 · D-147, D-150
 */

use App\Models\Enrollment;
use App\Services\Reports\CohortAverages;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->admin = makeAdmin();
    $this->cohort = makeCohort(['status' => 'running', 'name' => 'Cohort Averages', 'min_attendance_rate' => 75]);
    makeTrainer($this->cohort);

    // Four sessions that ended weeks ago.
    $this->sessions = [];

    foreach ([5, 12, 19, 26] as $day) {
        $start = riyadhAt('2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 18:00:00');
        $this->sessions[] = sessionInCohort($this->cohort, $start, $start->addHours(3));
    }

    $this->sara = makeParticipant($this->cohort);
    $this->omar = makeParticipant($this->cohort);

    // Sara attended three of four, Omar one of four.
    foreach (array_slice($this->sessions, 0, 3) as $session) {
        makeAttendance($session, $this->sara, 'present');
    }
    makeAttendance($this->sessions[0], $this->omar, 'present');

    // Sara earned 20 from an assignment and 30 from the project; Omar nothing yet.
    gradeAssignment($this->cohort, $this->sara, 30, 20);
    $project = makeFinalProject($this->cohort);
    makeEvaluation('final_project', makeProjectSubmission($project, $this->sara)->id, $this->sara, 30);
});

function averagesOf(object $test, ?array $cohorts = null): array
{
    return app(CohortAverages::class)->of($cohorts ?? [$test->cohort]);
}

it('D-150: متوسط الدفعة هو متوسط ما تقوله الخدمتان لكل متدرب فيها — وهو ما تعرضه شاشة الشهادات', function (): void {
    $averages = averagesOf($this);
    $mine = $averages['cohorts'][$this->cohort->id];

    $rates = [
        certificateEligibility()->attendanceRate($this->sara, $this->cohort),
        certificateEligibility()->attendanceRate($this->omar, $this->cohort),
    ];
    $marks = [
        scoreCalculator()->finalScore($this->sara, $this->cohort),
        scoreCalculator()->finalScore($this->omar, $this->cohort),
    ];

    expect($rates)->toBe([75.0, 25.0])
        ->and($marks)->toBe([50.0, 0.0])
        ->and($mine['attendance'])->toBe(array_sum($rates) / 2)
        ->and($mine['score'])->toBe(array_sum($marks) / 2)
        ->and($mine['attendance'])->toBe(50.0)
        ->and($mine['score'])->toBe(25.0);
});

it('D-150: مدرب ومنسّق ومنسحب ومن لم يُقبل بعد لا يدخلون المتوسط — المتدربون المقيّدون وحدهم', function (): void {
    makeCoordinator($this->cohort);

    $withdrew = makeParticipant($this->cohort);
    Enrollment::query()->where('user_id', $withdrew->id)->update(['status' => 'withdrawn']);
    $pending = makeParticipant($this->cohort);
    Enrollment::query()->where('user_id', $pending->id)->update(['status' => 'pending']);

    // Both would pull the average to 100% if they were counted.
    foreach ($this->sessions as $session) {
        makeAttendance($session, $withdrew, 'present');
        makeAttendance($session, $pending, 'present');
    }

    expect(averagesOf($this)['cohorts'][$this->cohort->id]['attendance'])->toBe(50.0);
});

it('D-150: من أتمّ البرنامج (حالته «مكتمل») يدخل متوسط دفعته المنتهية', function (): void {
    $this->cohort->update(['status' => 'completed']);
    Enrollment::query()->whereIn('user_id', [$this->sara->id, $this->omar->id])->update(['status' => 'completed']);

    expect(averagesOf($this)['cohorts'][$this->cohort->id]['attendance'])->toBe(50.0)
        ->and(averagesOf($this)['cohorts'][$this->cohort->id]['score'])->toBe(25.0);
});

it('D-150: عمودا enrollments القديمان لا يضعان رقمًا على الشاشة — الخدمتان وحدهما', function (): void {
    Enrollment::query()->update(['attendance_rate' => 5, 'final_score' => 5]);

    $mine = averagesOf($this)['cohorts'][$this->cohort->id];

    expect($mine['attendance'])->toBe(50.0)->and($mine['score'])->toBe(25.0);

    $reports = $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()->getContent();

    expect($reports)->toContain('50%')->and($reports)->toContain('25 / 100');
});

it('D-150: لا جلسة انتهت ولا شيء رُصد = «—» لا صفرًا — وجلسة انتهت بلا حاضرين = 0% مقيسة', function (): void {
    $fresh = makeCohort(['status' => 'upcoming', 'name' => 'Cohort Fresh']);
    makeParticipant($fresh);

    $quiet = makeCohort(['status' => 'running', 'name' => 'Cohort Quiet']);
    makeParticipant($quiet);
    $start = riyadhAt('2026-10-05 18:00:00');
    sessionInCohort($quiet, $start, $start->addHours(3));

    $empty = makeCohort(['status' => 'upcoming', 'name' => 'Cohort Empty']);

    $averages = averagesOf($this, [$fresh, $quiet, $empty])['cohorts'];

    expect($averages[$fresh->id])->toBe(['attendance' => null, 'score' => null])
        // A session ended and nobody came: that IS a measurement — zero percent. Nothing was marked.
        ->and($averages[$quiet->id])->toBe(['attendance' => 0.0, 'score' => null])
        ->and($averages[$empty->id])->toBe(['attendance' => null, 'score' => null]);
});

it('D-150: المتوسط العام يجمع المتدربين لا متوسطات الدفعات — دفعة كبيرة لا تساوي صغيرة', function (): void {
    $small = makeCohort(['status' => 'running', 'name' => 'Cohort Small']);
    $only = makeParticipant($small);

    foreach ([5, 12, 19, 26] as $day) {
        $start = riyadhAt('2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 18:00:00');
        makeAttendance(sessionInCohort($small, $start, $start->addHours(3)), $only, 'present');
    }

    $averages = averagesOf($this, [$this->cohort, $small]);

    // 75 and 25 in the first cohort, 100 in the second: three people, (75 + 25 + 100) / 3.
    expect($averages['overall']['attendance'])->toBe(200.0 / 3)
        ->and($averages['cohorts'][$small->id]['attendance'])->toBe(100.0);
});

it('D-150: التقارير ولوحة المشرف تعرضان الرقمين، والمخطط له عمود للدفعة المقيسة', function (): void {
    $reports = $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk();
    $dash = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

    $row = collect($reports->viewData('cohortRows')->items())->firstWhere('cohortName', 'Cohort Averages');

    expect($row->averageAttendanceLabel)->toBe('50%')
        ->and($row->averageScoreLabel)->toBe('25 / 100')
        ->and($reports->viewData('summary')->averageAttendanceLabel)->toBe('50%')
        ->and($reports->viewData('summary')->averageScoreLabel)->toBe('25 / 100')
        ->and(collect($reports->viewData('attendanceByCohort'))->count())->toBe(1)
        ->and($dash)->toContain('50%')
        ->and($dash)->toContain('25 / 100');
});

it('art. 19: استعلامات التقارير ولوحة المشرف لا تزيد بعدد المتدربين', function (): void {
    $queries = function (string $route): int {
        Illuminate\Support\Facades\Auth::forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route($route))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // The first request of a run does one-time work (settings, schema): measure a warm one.
    $queries('admin.reports.index');
    $queries('admin.dashboard');

    $reportsBefore = $queries('admin.reports.index');
    $dashboardBefore = $queries('admin.dashboard');

    for ($i = 0; $i < 12; $i++) {
        $person = makeParticipant($this->cohort);
        gradeAssignment($this->cohort, $person, 10, 5);
        makeAttendance($this->sessions[0], $person, 'present');
    }

    expect($queries('admin.reports.index'))->toBe($reportsBefore)
        ->and($queries('admin.dashboard'))->toBe($dashboardBefore);
});
