<?php

declare(strict_types=1);

/**
 * Phase 5 — a figure nobody measured is not zero.
 *
 * The reports and the dashboard average two columns of `enrollments`
 * (`attendance_rate`, `final_score`). Nothing writes `final_score` at all and
 * `attendance_rate` only when the reconciler has run, so both were NULL, the average of
 * nothing became «0%» and «0 / 100», and the screen told the administrator the cohort had
 * scored zero. Until the owner decides where these numbers come from (D-150), an unmeasured
 * average is a dash. The numbers themselves are not touched.
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
});

it('D-147: متى وُجد قياس يُعرض رقمه كما هو', function (): void {
    Enrollment::query()->where('user_id', $this->person->id)->update(['attendance_rate' => 80, 'final_score' => 72]);

    $reports = $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()->getContent();

    expect($reports)->toContain('80%')->and($reports)->toContain('72 / 100');
});

it('D-147: تصدير التقارير يكتب حالة الدفعة بالعربية ويعدّ المتدربين وحدهم', function (): void {
    $csv = $this->actingAs($this->admin)->get(route('admin.reports.export'))->assertOk()->getContent();

    expect($csv)->toContain(__('enums.cohort_status.running'))
        ->and($csv)->not->toContain('running')
        // One participant and one trainer are enrolled: the file counts one.
        ->and($csv)->toMatch('/Cohort Figures[^\n]*"?1"?,/');
});
