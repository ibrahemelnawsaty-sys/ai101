<?php

declare(strict_types=1);

/**
 * Phase 5 — a figure nobody measured is not zero.
 *
 * The reports and the dashboard average two columns of `enrollments`
 * (`attendance_rate`, `final_score`). Nothing writes `final_score` at all and
 * `attendance_rate` only when the reconciler has run, so both were NULL, the average of
 * nothing became «0%» and «0 / 100», and the screen told the administrator the cohort had
 * scored zero. An unmeasured average is a dash; and now that the owner has decided where the
 * numbers come from (D-150, ReportAveragesTest), a measured one is what the services say.
 *
 * The export said `running` in English and counted trainers and coordinators as
 * «participants»; it now says what the screen says.
 *
 * @see BR-22 · PRD §9.18 · D-147, D-150
 */

use App\Models\Enrollment;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    $this->admin = makeAdmin();
    $this->cohort = makeCohort(['status' => 'running', 'name' => 'Cohort Figures']);
    $this->person = makeParticipant($this->cohort);
    makeTrainer($this->cohort);
});

it('D-147: متوسطا الحضور والدرجة بلا قياس يُعرضان «—» لا «0%» و«0 / 100» — في التقارير والوحة', function (): void {
    $reports = $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()->getContent();
    $dash = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($reports)->not->toContain('0 / 100')
        ->and($dash)->not->toContain('0 / 100');

    // Positively: the dash IS what is drawn — in the headline cards, the cohort table and the
    // dashboard — and the attendance chart has no bar for a cohort nobody measured.
    preg_match_all('/<div class="[^"]*stat[^"]*"[^>]*>.*?<\/div>/s', $reports, $cards);

    expect(substr_count($reports, '—'))->toBeGreaterThanOrEqual(4)
        ->and(substr_count($dash, '—'))->toBeGreaterThanOrEqual(2)
        ->and($reports)->not->toContain('trend__v u-num">0%')
        ->and($reports)->toContain(__('admin.reports.empty_title'));
});

it('D-147: متى وُجد قياس يُعرض رقمه كما تحسبه الخدمتان — لا ما في عمودين لا يكتبهما أحد', function (): void {
    // One session has ended and the participant attended it: a real measurement (D-150).
    $start = riyadhAt('2026-09-20 18:00:00');
    makeAttendance(sessionInCohort($this->cohort, $start, $start->addHours(3)), $this->person, 'present');

    // The stale columns say something else entirely; they are not read.
    Enrollment::query()->where('user_id', $this->person->id)->update(['attendance_rate' => 10, 'final_score' => 99]);

    $reports = $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()->getContent();

    expect($reports)->toContain('100%')
        ->and($reports)->not->toContain('99 / 100')
        ->and($reports)->not->toContain('10%');
});

it('D-147: تصدير التقارير يكتب حالة الدفعة بالعربية ويعدّ المتدربين وحدهم', function (): void {
    $csv = $this->actingAs($this->admin)->get(route('admin.reports.export'))->assertOk()->getContent();

    expect($csv)->toContain(__('enums.cohort_status.running'))
        ->and($csv)->not->toContain('running')
        // One participant and one trainer are enrolled: the file counts one.
        ->and($csv)->toMatch('/Cohort Figures[^\n]*"?1"?,/');
});
