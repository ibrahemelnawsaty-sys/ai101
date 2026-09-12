<?php

declare(strict_types=1);

/**
 * The links out of a trainer's participant profile land where they say.
 *
 * WHY THIS SUITE EXISTS
 * The grade button sent `?grade=` after the board had been renamed to read
 * `?submission=`, and sent no cohort — so it opened the default cohort's board
 * with the grading panel shut. And it pointed at the OLDEST version of the
 * work: grading that would replace the mark that counts with a mark for a
 * superseded file (D-72). No test had ever opened a profile and followed a
 * link out of it.
 *
 * @see BR-19, BR-23 · PRD §9.15 · D-72
 */

use App\Http\Middleware\EnsureCohortScope;
use App\Models\Evaluation;
use App\Presenters\Trainer\GradingForm;
use App\Services\Permissions\RoleResolver;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-19 12:00:00'));

    // Two cohorts for one trainer, and the profile opened in the one that is
    // NOT the default — chosen on purpose, so a link that drops the cohort
    // cannot pass by landing on the default by luck.
    $this->trainer = makeTrainer();
    $first = makeCohort();
    $second = makeCohort();
    enroll($this->trainer, $first, 'trainer');
    enroll($this->trainer, $second, 'trainer');

    $default = (new RoleResolver)->trainerCohortIds($this->trainer)[0] ?? null;
    $this->target = (string) $first->id === $default ? $second : $first;
    $this->participant = makeParticipant($this->target);
});

it('BR-23: زرّ التصحيح في ملف المتدرّب يفتح لوحة التصحيح على تسليمه، في دفعة الملف', function (): void {
    $assignment = makeAssignment($this->target);
    $submission = makeSubmission($assignment, $this->participant, ['note' => 'CANARY-NOTE']);

    $grade = route('trainer.submissions', [
        EnsureCohortScope::QUERY_KEY => (string) $this->target->id,
        GradingForm::QUERY_KEY => (string) $submission->id,
    ]);

    $this->actingAs($this->trainer)
        ->get(route('trainer.participants', [EnsureCohortScope::QUERY_KEY => $this->target->id, 'view' => $this->participant->id]))
        ->assertOk()
        ->assertSee(e($grade), false)
        ->assertSee(e(route('trainer.attendance', [EnsureCohortScope::QUERY_KEY => (string) $this->target->id])), false);

    $this->actingAs($this->trainer)->get($grade)->assertOk()->assertSee('CANARY-NOTE', false);
});

it('BR-19: ملف المتدرّب يقرن كل مهمة بأحدث نسخة منها', function (): void {
    $assignment = makeAssignment($this->target);
    $v1 = makeSubmission($assignment, $this->participant, ['version' => 1]);
    Evaluation::factory()->create([
        'entity_type' => 'assignment', 'entity_id' => $v1->id, 'user_id' => $this->participant->id,
        'score' => 7, 'evaluated_by' => $this->trainer->id, 'evaluated_at' => riyadhAt('2026-10-18 10:00:00'),
    ]);
    $v2 = makeSubmission($assignment, $this->participant, ['version' => 2]);

    $this->actingAs($this->trainer)
        ->get(route('trainer.participants', [EnsureCohortScope::QUERY_KEY => $this->target->id, 'view' => $this->participant->id]))
        ->assertOk()
        ->assertSee(GradingForm::QUERY_KEY.'='.$v2->id, false)
        ->assertDontSee(GradingForm::QUERY_KEY.'='.$v1->id, false);
});
