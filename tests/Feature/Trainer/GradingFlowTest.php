<?php

declare(strict_types=1);

/**
 * Phase 2 — the two grading screens finish what their buttons promise.
 *
 * 2-A: a final project that already carries a mark is AMENDED through the
 *      amendment endpoint with a written reason (BR-14); its panel used to post
 *      to the record endpoint, which refuses a second mark ("already graded").
 * 2-B: «save and go to the next» opens the oldest hand-in still waiting for a
 *      mark; the button posted `next=1` and nothing read it.
 *
 * "Waiting" means: no evaluation yet, the newest version of that trainee's
 * hand-in (BR-19 keeps every version — an older one is never offered), the SAME
 * assignment or the SAME project, the same cohort. Nothing here changes how a
 * mark is computed or recorded; it only decides where the trainer lands after.
 *
 * @see BR-12, BR-13, BR-14, BR-19, BR-23 · FR-ASGN-29, FR-GRADE-15 · PRD §9.15 · D-136
 */

use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\Notification;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-02 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60]);
    $this->trainer = makeTrainer($this->cohort);
    $this->feedback = 'Clear structure, but the evaluation section needs more depth.';
    $this->reason = 'The rubric was applied to the wrong criterion on first pass.';
});

/*
|--------------------------------------------------------------------------
| 2-A — amending a final project's mark
|--------------------------------------------------------------------------
*/

function gradedProject(object $test, float $score = 30): array
{
    $project = makeFinalProject($test->cohort, ['is_unlocked' => true]);
    $trainee = makeParticipant($test->cohort);
    $submission = makeProjectSubmission($project, $trainee);
    $evaluation = makeEvaluation('final_project', $submission->id, $trainee, $score, [
        'evaluated_by' => $test->trainer->id,
    ]);

    return [$project, $trainee, $submission, $evaluation];
}

it('BR-14: لوحة مشروع ختامي مُقيَّم ترسل التعديل إلى مسار التعديل ومعه سبب التعديل', function (): void {
    [, , $submission, $evaluation] = gradedProject($this);

    $page = $this->actingAs($this->trainer)
        ->get(route('trainer.finalProject', ['cohort' => $this->cohort->id, 'grade' => $submission->id]))
        ->assertOk();

    $page->assertSee(route('trainer.submissions.revise', $evaluation), escape: false)
        ->assertSee('name="_method" value="PATCH"', escape: false)
        ->assertSee('name="revision_reason"', escape: false)
        ->assertDontSee(route('trainer.finalProject.grade', $submission), escape: false);
});

it('BR-14: لوحة مشروع ختامي لم يُقيَّم بعد ترسل إلى مسار الرصد بلا حقل سبب', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $submission = makeProjectSubmission($project, makeParticipant($this->cohort));

    $this->actingAs($this->trainer)
        ->get(route('trainer.finalProject', ['cohort' => $this->cohort->id, 'grade' => $submission->id]))
        ->assertOk()
        ->assertSee(route('trainer.finalProject.grade', $submission), escape: false)
        ->assertDontSee('name="revision_reason"', escape: false)
        ->assertDontSee('name="_method" value="PATCH"', escape: false);
});

it('BR-14: تعديل درجة مشروع ختامي بسبب مكتوب يُقبل ويُسجَّل ويُشعر المتدرب', function (): void {
    [, $trainee, , $evaluation] = gradedProject($this, 30);
    Notification::query()->delete();

    assertAccepted($this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 44,
        'feedback' => $this->feedback,
        'revision_reason' => $this->reason,
    ]));

    expect((float) $evaluation->fresh()->score)->toBe(44.0)
        ->and(AuditLog::query()
            ->where('entity_type', 'evaluation')
            ->where('entity_id', $evaluation->id)
            ->where('actor_id', $this->trainer->id)
            ->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $trainee->id)->count())->toBeGreaterThan(0);
});

it('BR-14: تعديل درجة مشروع ختامي بلا سبب مرفوض والدرجة تبقى', function (): void {
    [, , , $evaluation] = gradedProject($this, 30);

    assertRefused($this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 44,
        'feedback' => $this->feedback,
    ]));

    expect((float) $evaluation->fresh()->score)->toBe(30.0);
});

it('BR-12: تعديل درجة مشروع ختامي فوق سقفه مرفوض والسقف بالضبط مقبول', function (): void {
    [, , , $evaluation] = gradedProject($this, 30);

    assertRefused($this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 50.01,
        'feedback' => $this->feedback,
        'revision_reason' => $this->reason,
    ]));
    expect((float) $evaluation->fresh()->score)->toBe(30.0);

    assertAccepted($this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 50,
        'feedback' => $this->feedback,
        'revision_reason' => $this->reason,
    ]));
    expect((float) $evaluation->fresh()->score)->toBe(50.0);
});

it('BR-23: مدرّب دفعة أخرى لا يعدّل تقييم مشروع ختامي ليس من دفعته', function (): void {
    [, , , $evaluation] = gradedProject($this, 30);

    $outsider = makeTrainer(makeCohort());

    $this->actingAs($outsider)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 44,
        'feedback' => $this->feedback,
        'revision_reason' => $this->reason,
    ])->assertForbidden();

    expect((float) $evaluation->fresh()->score)->toBe(30.0);
})->group('authz');

/*
|--------------------------------------------------------------------------
| 2-B — «save and go to the next», assignment board
|--------------------------------------------------------------------------
*/

function boardCase(object $test): void
{
    $test->assignment = makeAssignment($test->cohort, ['max_score' => 10]);
    $test->a = makeParticipant($test->cohort);
    $test->b = makeParticipant($test->cohort);
    $test->c = makeParticipant($test->cohort);

    // Oldest first: a (19th 08:00) < b (19th 09:00) < c (19th 10:00).
    $test->sa = makeSubmission($test->assignment, $test->a, ['submitted_at' => riyadhAt('2026-10-19 08:00:00')]);
    $test->sb = makeSubmission($test->assignment, $test->b, ['submitted_at' => riyadhAt('2026-10-19 09:00:00')]);
    $test->sc = makeSubmission($test->assignment, $test->c, ['submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
}

function gradeBody(object $test, array $extra = []): array
{
    return $extra + ['score' => 8, 'feedback' => $test->feedback];
}

it('FR-ASGN-29: حفظ مع next يفتح أقدم تسليم غير مُقيَّم في المهمة نفسها', function (): void {
    boardCase($this);

    $response = $this->actingAs($this->trainer)->post(
        route('trainer.submissions.grade', $this->sc),
        gradeBody($this, ['next' => 1]),
    );

    $response->assertRedirect(route('trainer.submissions', [
        'cohort' => $this->cohort->id,
        'assignment' => $this->assignment->id,
        'submission' => $this->sa->id,
    ]));
    expect(Evaluation::query()->where('entity_id', $this->sc->id)->count())->toBe(1);
});

it('FR-ASGN-29: التالي يتخطّى ما قُيِّم وما فيه نسخة أحدث من المتدرب نفسه', function (): void {
    boardCase($this);
    makeEvaluation('assignment', $this->sa->id, $this->a, 9);

    // b hands in again: v1 must never be offered, only v2.
    $v2 = makeSubmission($this->assignment, $this->b, [
        'version' => 2,
        'submitted_at' => riyadhAt('2026-10-20 07:00:00'),
    ]);

    $this->actingAs($this->trainer)->post(
        route('trainer.submissions.grade', $this->sc),
        gradeBody($this, ['next' => 1]),
    )->assertRedirect(route('trainer.submissions', [
        'cohort' => $this->cohort->id,
        'assignment' => $this->assignment->id,
        'submission' => $v2->id,
    ]));
});

it('FR-ASGN-29: حين لا يبقى تسليم ينتظر يعود إلى اللوحة بلا لوحة تقييم مفتوحة وبرسالة واضحة', function (): void {
    boardCase($this);
    makeEvaluation('assignment', $this->sa->id, $this->a, 9);
    makeEvaluation('assignment', $this->sb->id, $this->b, 9);

    // Another assignment's waiting hand-in must not be offered.
    $other = makeAssignment($this->cohort, ['max_score' => 10]);
    makeSubmission($other, makeParticipant($this->cohort));

    $response = $this->actingAs($this->trainer)->post(
        route('trainer.submissions.grade', $this->sc),
        gradeBody($this, ['next' => 1]),
    );

    $response->assertRedirect(route('trainer.submissions', [
        'cohort' => $this->cohort->id,
        'assignment' => $this->assignment->id,
    ]));
    // One toast: the mark WAS recorded, and there is nothing left waiting. Not a
    // warning — finishing the queue is good news.
    $response->assertSessionHas('status', __('grades.recorded').' '.__('trainer.grading.queue_done'));
    $response->assertSessionMissing('warning');
});

it('BR-23: التالي لا يعبر إلى دفعة أخرى', function (): void {
    boardCase($this);
    makeEvaluation('assignment', $this->sa->id, $this->a, 9);
    makeEvaluation('assignment', $this->sb->id, $this->b, 9);

    $foreign = makeCohort();
    $foreignAssignment = makeAssignment($foreign);
    makeSubmission($foreignAssignment, makeParticipant($foreign), [
        'submitted_at' => riyadhAt('2026-10-18 08:00:00'),
    ]);

    $location = $this->actingAs($this->trainer)->post(
        route('trainer.submissions.grade', $this->sc),
        gradeBody($this, ['next' => 1]),
    )->headers->get('Location');

    expect($location)->not->toContain('submission=');
});

it('FR-ASGN-29: المرشّحات الحالية للوحة تبقى بعد الانتقال ولا يتسرّب منها شيء غير معروف', function (): void {
    boardCase($this);

    $url = route('trainer.submissions.grade', [
        'submission' => $this->sc->id,
        'cohort' => $this->cohort->id,
        'assignment' => $this->assignment->id,
        'status' => 'submitted',
        'q' => 'سارة',
        'redirect' => 'https://evil.example/',
    ]);

    $location = $this->actingAs($this->trainer)
        ->post($url, gradeBody($this, ['next' => 1]))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith(route('trainer.submissions'))
        ->and($location)->toContain('status=submitted')
        ->and($location)->toContain('submission='.$this->sa->id)
        ->and($location)->not->toContain('evil.example')
        ->and($location)->not->toContain('redirect=');
});

it('FR-ASGN-29: حفظ بلا next يبقى في الصفحة كما كان', function (): void {
    boardCase($this);

    $this->actingAs($this->trainer)
        ->from(route('trainer.submissions', ['cohort' => $this->cohort->id, 'submission' => $this->sc->id]))
        ->post(route('trainer.submissions.grade', $this->sc), gradeBody($this))
        ->assertRedirect(route('trainer.submissions', ['cohort' => $this->cohort->id, 'submission' => $this->sc->id]));
});

it('BR-13: فشل التحقق مع next لا ينتقل ولا يحفظ', function (): void {
    boardCase($this);

    assertRefused($this->actingAs($this->trainer)->post(
        route('trainer.submissions.grade', $this->sc),
        ['score' => 8, 'feedback' => 'قصير', 'next' => 1],
    ));

    expect(Evaluation::query()->count())->toBe(0);
});

it('BR-14: تعديل درجة مرصودة مع next ينتقل إلى أقدم تسليم ينتظر', function (): void {
    boardCase($this);
    $evaluation = makeEvaluation('assignment', $this->sc->id, $this->c, 6, ['evaluated_by' => $this->trainer->id]);

    $this->actingAs($this->trainer)->patch(
        route('trainer.submissions.revise', $evaluation),
        ['score' => 9, 'feedback' => $this->feedback, 'revision_reason' => $this->reason, 'next' => 1],
    )->assertRedirect(route('trainer.submissions', [
        'cohort' => $this->cohort->id,
        'assignment' => $this->assignment->id,
        'submission' => $this->sa->id,
    ]));

    expect((float) $evaluation->fresh()->score)->toBe(9.0);
});

it('FR-ASGN-29: next غير منطقي يُرفض بدل أن يُتجاهل', function (): void {
    boardCase($this);

    assertRefused($this->actingAs($this->trainer)->post(
        route('trainer.submissions.grade', $this->sc),
        gradeBody($this, ['next' => 'maybe']),
    ));
});

it('FR-ASGN-29: لوحة التقييم ترسل المرشّحات الحالية مع النموذج وفيها زر «حفظ والانتقال للتالي»', function (): void {
    boardCase($this);

    $page = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', [
            'cohort' => $this->cohort->id,
            'assignment' => $this->assignment->id,
            'status' => 'submitted',
            'submission' => $this->sc->id,
        ]))
        ->assertOk();

    $page->assertSee('name="next"', escape: false)
        ->assertSee('assignment='.$this->assignment->id, escape: false);
});

/*
|--------------------------------------------------------------------------
| 2-B — «save and go to the next», final project
|--------------------------------------------------------------------------
*/

function projectCase(object $test): void
{
    $test->project = makeFinalProject($test->cohort, ['is_unlocked' => true]);
    $test->a = makeParticipant($test->cohort);
    $test->b = makeParticipant($test->cohort);
    $test->c = makeParticipant($test->cohort);

    $test->pa = makeProjectSubmission($test->project, $test->a, ['submitted_at' => riyadhAt('2026-11-01 08:00:00')]);
    $test->pb = makeProjectSubmission($test->project, $test->b, ['submitted_at' => riyadhAt('2026-11-01 09:00:00')]);
    $test->pc = makeProjectSubmission($test->project, $test->c, ['submitted_at' => riyadhAt('2026-11-01 10:00:00')]);
}

it('FR-ASGN-29: رصد درجة مشروع ختامي مع next يفتح أقدم تسليم ينتظر', function (): void {
    projectCase($this);

    $this->actingAs($this->trainer)->post(
        route('trainer.finalProject.grade', $this->pc),
        ['score' => 40, 'feedback' => $this->feedback, 'next' => 1],
    )->assertRedirect(route('trainer.finalProject', [
        'cohort' => $this->cohort->id,
        'grade' => $this->pa->id,
    ]));

    expect(Evaluation::query()->where('entity_id', $this->pc->id)->count())->toBe(1);
});

it('FR-ASGN-29: حين لا يبقى مشروع ينتظر تعود الشاشة إلى الجدول برسالة واضحة', function (): void {
    projectCase($this);
    makeEvaluation('final_project', $this->pa->id, $this->a, 40);
    makeEvaluation('final_project', $this->pb->id, $this->b, 40);

    $response = $this->actingAs($this->trainer)->post(
        route('trainer.finalProject.grade', $this->pc),
        ['score' => 40, 'feedback' => $this->feedback, 'next' => 1],
    );

    $response->assertRedirect(route('trainer.finalProject', ['cohort' => $this->cohort->id]));
    $response->assertSessionHas('status', __('grades.recorded').' '.__('trainer.grading.queue_done'));
    $response->assertSessionMissing('warning');
});

it('FR-ASGN-29: تعديل درجة مشروع ختامي مع next يعود إلى شاشة المشروع لا إلى لوحة المهام', function (): void {
    projectCase($this);
    $evaluation = makeEvaluation('final_project', $this->pc->id, $this->c, 30, ['evaluated_by' => $this->trainer->id]);

    $this->actingAs($this->trainer)->patch(
        route('trainer.submissions.revise', $evaluation),
        ['score' => 45, 'feedback' => $this->feedback, 'revision_reason' => $this->reason, 'next' => 1],
    )->assertRedirect(route('trainer.finalProject', [
        'cohort' => $this->cohort->id,
        'grade' => $this->pa->id,
    ]));
});

it('FR-ASGN-29: شاشة المشروع الختامي فيها زر «حفظ والانتقال للتالي» للرصد وللتعديل', function (): void {
    projectCase($this);
    $graded = makeEvaluation('final_project', $this->pb->id, $this->b, 30);

    foreach ([$this->pa, $this->pb] as $submission) {
        $this->actingAs($this->trainer)
            ->get(route('trainer.finalProject', ['cohort' => $this->cohort->id, 'grade' => $submission->id]))
            ->assertOk()
            ->assertSee('name="next"', escape: false);
    }
});
