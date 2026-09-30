<?php

declare(strict_types=1);

/**
 * Phase 5 — the cohort form's programme control.
 *
 * The edit form showed the programme as an ordinary choice, and UpdateCohortRequest
 * has no such field: the server ignores whatever is picked and saves the rest. A
 * control that looks changeable and silently does nothing is a false promise, so in
 * edit mode it is shown locked with the reason; creating still chooses one. The
 * server's behaviour is not changed and is asserted here so it cannot drift apart
 * from the screen again.
 *
 * @see BR-31 · PRD §9.18 · CONSTITUTION art. 5, art. 17 · D-147
 */

use App\Models\Program;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    $this->admin = makeAdmin();
    $this->program = Program::factory()->create();
    $this->cohort = makeCohort(['program_id' => $this->program->id, 'name' => 'Cohort Locked']);
});

it('D-147: تعديل الدفعة يعرض البرنامج مقفلًا مع سببه', function (): void {
    $html = $this->actingAs($this->admin)
        ->get(route('admin.cohorts.index', ['edit' => $this->cohort->id]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(__('admin.cohorts.fields.program_locked_hint'))
        ->and($html)->toMatch('/<button[^>]*ui-select__button[^>]*disabled/s');
});

it('D-147: إنشاء دفعة جديدة يترك اختيار البرنامج مفتوحًا بلا تلميح القفل', function (): void {
    $html = $this->actingAs($this->admin)
        ->get(route('admin.cohorts.index', ['edit' => 'new']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(__('admin.cohorts.fields.program_locked_hint'))
        ->and($html)->not->toMatch('/<button[^>]*ui-select__button[^>]*disabled/s');
});

it('D-147: الخادم يتجاهل برنامجًا آخر في التعديل — الدفعة تبقى في برنامجها', function (): void {
    $other = Program::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.cohorts.update', $this->cohort), [
        'name' => 'Cohort Renamed',
        'program_id' => $other->id,
        'starts_at' => '2026-11-01',
        'ends_at' => '2026-12-01',
        'capacity' => 30,
        'pass_score' => 60,
        'min_attendance_rate' => 75,
        'status' => 'upcoming',
    ])->assertSessionHasNoErrors();

    $fresh = $this->cohort->fresh();

    expect($fresh->name)->toBe('Cohort Renamed')
        ->and($fresh->program_id)->toBe($this->program->id);
});
