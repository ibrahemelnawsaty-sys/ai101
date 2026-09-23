<?php

declare(strict_types=1);

/**
 * D-111: defining a weekly task (its conditions, description, deadline and
 * files) moved from trainer+admin to admin only; `trainer.assignments` stays
 * reachable by the trainer, read-only — grading (trainer.submissions) is
 * untouched.
 *
 * @see BR-17 · D-111 · PRD §9.11.3 · CONSTITUTION Art. 5, Art. 22
 */

it('D-111: المدرب يرى قائمة مهامه الأسبوعية للاطّلاع فقط دون زر إنشاء', function (): void {
    $cohort = makeCohort();
    $trainer = makeTrainer($cohort);
    makeAssignment($cohort, ['title' => 'CANARY-WEEKLY-TASK']);

    $this->actingAs($trainer)
        ->get(route('trainer.assignments', ['cohort' => $cohort->id]))
        ->assertOk()
        ->assertSee(__('trainer.assignments.readonly_title'))
        ->assertSee('CANARY-WEEKLY-TASK')
        ->assertDontSee(__('trainer.assignments.create'));
});

it('D-111: المشرف العام يرى نموذج الإدارة الكامل لإنشاء مهمة أسبوعية', function (): void {
    $cohort = makeCohort();
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->get(route('trainer.assignments', ['cohort' => $cohort->id]))
        ->assertOk()
        ->assertSee(__('trainer.assignments.create'))
        ->assertDontSee(__('trainer.assignments.readonly_title'));
});
