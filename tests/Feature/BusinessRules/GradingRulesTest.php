<?php

declare(strict_types=1);

/**
 * BR-11 … BR-14: the grade ledger.
 *
 * Route names are the ones routes/web.php declares. A grade is recorded with POST
 * `trainer.submissions.grade` against ONE submission named in the URL, and amended
 * with PATCH `trainer.submissions.revise` against ONE evaluation named in the URL.
 * The graded item is never a body field: StoreEvaluationRequest reads the ceiling
 * from the assignment behind the route parameter, so a crafted payload cannot raise
 * its own maximum (BR-12).
 *
 * CONTRACT CONFLICT worth the product owner's attention: PRD §7.7 asks for a database
 * CHECK constraint "score does not exceed max_score", but max_score lives on the
 * assignment row, not on the evaluation row, so a single-table CHECK cannot express
 * it. This suite asserts the expressible half (score >= 0) at the database and the
 * whole rule at the server, which is where it is actually enforceable.
 *
 * @see BR-11, BR-12, BR-13, BR-14 · PRD §9.15 · PROJECT-CONTRACT.md §7
 */

use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\Notification;
use App\Services\Grading\ScoreCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    freezeAt(riyadhAt('2026-10-21 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60]);
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);

    $this->assignment = makeAssignment($this->cohort, ['max_score' => 10]);
    $this->submission = makeSubmission($this->assignment, $this->participant);

    $this->goodFeedback = 'Clear structure, but the evaluation section needs more depth.';
});

/*
|--------------------------------------------------------------------------
| BR-11 — 50 + 50 = 100
|--------------------------------------------------------------------------
*/

it('BR-11: مجموع درجات المهام خمسون ودرجة المشروع خمسون والمجموع الكلي مئة', function () {
    expect(ScoreCalculator::ASSIGNMENTS_TOTAL + ScoreCalculator::PROJECT_TOTAL)
        ->toBe(ScoreCalculator::GRAND_TOTAL)
        ->and(ScoreCalculator::GRAND_TOTAL)->toBe(100);
});

it('BR-11: صفحة الدرجات تعرض المجموع من مئة موزّعًا خمسين وخمسين', function () {
    makeEvaluation('assignment', $this->submission->id, $this->participant, 8);

    $response = $this->actingAs($this->participant)->get(route('grades'));

    $response->assertOk()
        ->assertSee('50', escape: false)
        ->assertSee('100', escape: false);

    expect(scoreCalculator()->finalScore($this->participant, $this->cohort))->toBe(8.0);
});

/*
|--------------------------------------------------------------------------
| BR-12 — the score stays inside its bounds
|--------------------------------------------------------------------------
*/

it('BR-12: رصد درجة أكبر من الدرجة القصوى مرفوض على الخادم', function () {
    assertRefused($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => 10.01,
        'feedback' => $this->goodFeedback,
    ]));

    expect(Evaluation::query()->count())->toBe(0);
});

it('BR-12: الدرجة القصوى بالضبط مقبولة', function () {
    assertAccepted($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => 10,
        'feedback' => $this->goodFeedback,
    ]));

    expect((float) Evaluation::query()->sole()->score)->toBe(10.0);
});

it('BR-12: الدرجة السالبة مرفوضة على الخادم', function () {
    assertRefused($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => -0.01,
        'feedback' => $this->goodFeedback,
    ]));

    expect(Evaluation::query()->count())->toBe(0);
});

it('BR-12: الصفر درجة مشروعة', function () {
    assertAccepted($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => 0,
        'feedback' => $this->goodFeedback,
    ]));

    expect((float) Evaluation::query()->sole()->score)->toBe(0.0);
});

/*
 * MYSQL ONLY. The constraint is added by the migration with
 *
 *     ALTER TABLE `evaluations` ADD CONSTRAINT `evaluations_score_range_check` ...
 *
 * guarded by the driver name, so it simply does not exist on the SQLite connection
 * the suite uses when no database server is available. Asserting it there would
 * report a passing schema guarantee that had never been created — the exact class of
 * lie the suite exists to prevent. It is therefore tagged `mysql` and skipped with a
 * stated reason, and it is run for real with:
 *
 *     DB_CONNECTION=mysql DB_DATABASE=athar_testing vendor/bin/pest --group=mysql
 *
 * @see phpunit.xml · config/database.php · PRD §7.7
 */
it('BR-12: قاعدة البيانات نفسها ترفض درجة سالبة', function () {
    // The server-side guard is bypassed on purpose. PRD §7.7 requires the constraint
    // to exist in the schema, not only in the FormRequest.
    expect(fn () => DB::table('evaluations')->insert([
        'id' => (string) Str::uuid(),
        'entity_type' => 'assignment',
        'entity_id' => $this->submission->id,
        'user_id' => $this->participant->id,
        'score' => -1,
        'feedback' => $this->goodFeedback,
        'evaluated_by' => $this->trainer->id,
        'evaluated_at' => riyadhAt('2026-10-21 12:00:00')->toDateTimeString(),
        'created_at' => riyadhAt('2026-10-21 12:00:00')->toDateTimeString(),
        'updated_at' => riyadhAt('2026-10-21 12:00:00')->toDateTimeString(),
    ]))->toThrow(QueryException::class);

    expect(Evaluation::query()->count())->toBe(0);
})->group('mysql')->skip(fn (): bool => usingSqlite(), MYSQL_ONLY_REASON);

it('BR-12: الدرجات العشرية مدعومة بمنزلتين', function () {
    assertAccepted($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => 8.75,
        'feedback' => $this->goodFeedback,
    ]));

    expect((float) Evaluation::query()->sole()->score)->toBe(8.75);
});

/*
|--------------------------------------------------------------------------
| BR-13 — feedback is mandatory, and at least ten characters
|--------------------------------------------------------------------------
*/

it('BR-13: رصد درجة بلا ملاحظة مرفوض', function () {
    assertRefused($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => 8,
    ]));

    expect(Evaluation::query()->count())->toBe(0);
});

it('BR-13: ملاحظة أقصر من عشرة أحرف مرفوضة', function () {
    assertRefused($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => 8,
        'feedback' => str_repeat('n', 9),
    ]));

    expect(Evaluation::query()->count())->toBe(0);
});

it('BR-13: ملاحظة بعشرة أحرف بالضبط مقبولة', function () {
    assertAccepted($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => 8,
        'feedback' => str_repeat('n', 10),
    ]));

    expect(Evaluation::query()->count())->toBe(1);
});

it('BR-13: ملاحظة من مسافات فقط لا تُعد ملاحظة', function () {
    assertRefused($this->actingAs($this->trainer)->post(route('trainer.submissions.grade', $this->submission), [
        'score' => 8,
        'feedback' => str_repeat(' ', 20),
    ]));

    expect(Evaluation::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| BR-14 — revising a recorded grade
|--------------------------------------------------------------------------
*/

it('BR-14: تعديل درجة مرصودة بلا سبب مرفوض', function () {
    $evaluation = makeEvaluation('assignment', $this->submission->id, $this->participant, 6, [
        'evaluated_by' => $this->trainer->id,
    ]);

    assertRefused($this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 9,
        'feedback' => $this->goodFeedback,
    ]));

    expect((float) $evaluation->fresh()->score)->toBe(6.0);
});

it('BR-14: تعديل درجة مرصودة بسبب مكتوب يُقبل ويُسجَّل في سجل التدقيق', function () {
    $evaluation = makeEvaluation('assignment', $this->submission->id, $this->participant, 6, [
        'evaluated_by' => $this->trainer->id,
    ]);

    assertAccepted($this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 9,
        'feedback' => $this->goodFeedback,
        'revision_reason' => 'The rubric was applied to the wrong criterion on first pass.',
    ]));

    $fresh = $evaluation->fresh();

    expect((float) $fresh->score)->toBe(9.0)
        ->and($fresh->revision_reason)->not->toBeNull();

    expect(AuditLog::query()
        ->where('entity_type', 'evaluation')
        ->where('entity_id', $evaluation->id)
        ->where('actor_id', $this->trainer->id)
        ->count())->toBe(1);
});

it('BR-14: تعديل الدرجة يُشعر المتدرب', function () {
    $evaluation = makeEvaluation('assignment', $this->submission->id, $this->participant, 6, [
        'evaluated_by' => $this->trainer->id,
    ]);

    Notification::query()->delete();

    $this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 9,
        'feedback' => $this->goodFeedback,
        'revision_reason' => 'The rubric was applied to the wrong criterion on first pass.',
    ]);

    expect(Notification::query()->where('user_id', $this->participant->id)->count())->toBeGreaterThan(0);
});

it('BR-14: المجموع الكلي يتحدث فور تعديل الدرجة', function () {
    $evaluation = makeEvaluation('assignment', $this->submission->id, $this->participant, 6, [
        'evaluated_by' => $this->trainer->id,
    ]);

    expect(scoreCalculator()->finalScore($this->participant, $this->cohort))->toBe(6.0);

    $this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 9,
        'feedback' => $this->goodFeedback,
        'revision_reason' => 'The rubric was applied to the wrong criterion on first pass.',
    ]);

    expect(scoreCalculator()->finalScore($this->participant->fresh(), $this->cohort->fresh()))->toBe(9.0);
});

it('BR-14: التعديل لا يتجاوز الدرجة القصوى', function () {
    $evaluation = makeEvaluation('assignment', $this->submission->id, $this->participant, 6, [
        'evaluated_by' => $this->trainer->id,
    ]);

    assertRefused($this->actingAs($this->trainer)->patch(route('trainer.submissions.revise', $evaluation), [
        'score' => 11,
        'feedback' => $this->goodFeedback,
        'revision_reason' => 'The rubric was applied to the wrong criterion on first pass.',
    ]));

    expect((float) $evaluation->fresh()->score)->toBe(6.0);
});
