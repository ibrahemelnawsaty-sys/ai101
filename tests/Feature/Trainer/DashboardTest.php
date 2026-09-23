<?php

declare(strict_types=1);

/**
 * PR-5 دفعة 2: the trainer's own information dashboard — a screen that did
 * not exist before (the shared `/dashboard` route used to send a trainer
 * straight to the submissions queue). Every figure is reused from a service
 * or presenter another screen already trusts (ParticipantStats for BR-26,
 * TotalsWarning for BR-11), so this suite only proves the dashboard wires
 * them correctly and keeps its own cohort's scope (BR-23).
 *
 * @see BR-11, BR-23, BR-26 · PRD §9.9.6 · CONSTITUTION Art. 6, Art. 17
 */

use App\Presenters\Trainer\ParticipantStats;

it('لوحة معلومات المدرب تعرض إحصاءات دفعته فقط دون تسرّب من دفعة أخرى', function (): void {
    $cohort = makeCohort();
    $trainer = makeTrainer($cohort);
    makeParticipant($cohort);
    makeParticipant($cohort);

    $otherCohort = makeCohort();
    makeTrainer($otherCohort);
    makeParticipant($otherCohort);
    makeParticipant($otherCohort);
    makeParticipant($otherCohort);

    $this->actingAs($trainer)
        ->get(route('trainer.dashboard', ['cohort' => $cohort->id]))
        ->assertOk()
        ->assertViewHas('stats', fn (ParticipantStats $stats): bool => $stats->total === 2);
});

it('لوحة معلومات المدرب تحسب المهام والمشاريع بلا تصحيح فقط', function (): void {
    $cohort = makeCohort();
    $trainer = makeTrainer($cohort);
    $participant = makeParticipant($cohort);

    $assignment = makeAssignment($cohort, ['max_score' => 10]);
    $graded = makeSubmission($assignment, $participant);
    makeEvaluation('assignment', $graded->id, $participant, 8.0);

    $ungradedParticipant = makeParticipant($cohort);
    makeSubmission($assignment, $ungradedParticipant);

    $finalProject = makeFinalProject($cohort);
    makeProjectSubmission($finalProject, $participant);

    $this->actingAs($trainer)
        ->get(route('trainer.dashboard', ['cohort' => $cohort->id]))
        ->assertOk()
        ->assertViewHas('ungradedWeeklyTasks', 1)
        ->assertViewHas('ungradedFinalProject', 1);
});

it('لوحة معلومات المدرب تعرض الجلسة القادمة غير الملغاة الأقرب فقط', function (): void {
    $cohort = makeCohort();
    $trainer = makeTrainer($cohort);

    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    sessionInCohort($cohort, riyadhAt('2026-10-02 18:00:00'), riyadhAt('2026-10-02 20:00:00'), [
        'status' => 'cancelled',
        'cancellation_reason' => 'ظرف طارئ',
    ]);
    sessionInCohort($cohort, riyadhAt('2026-10-03 18:00:00'), riyadhAt('2026-10-03 20:00:00'), [
        'topic' => 'CANARY-NEXT-SESSION',
    ]);

    $this->actingAs($trainer)
        ->get(route('trainer.dashboard', ['cohort' => $cohort->id]))
        ->assertOk()
        ->assertSee('CANARY-NEXT-SESSION');
});

it('لوحة معلومات المدرب لا تنهار لمدرّب بلا دفعة مسندة', function (): void {
    $trainer = makeTrainer();

    $this->actingAs($trainer)
        ->get(route('trainer.dashboard'))
        ->assertOk()
        ->assertSee(__('trainer.dashboard.next_session_empty_title'));
});
