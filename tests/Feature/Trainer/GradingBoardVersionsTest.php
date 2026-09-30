<?php

declare(strict_types=1);

/**
 * Phase 4 (4-ب) — what the two grading boards LIST, and in what order.
 *
 * BR-19 keeps every version of a hand-in, and an older one that was handed in
 * again is never the one to mark (GradingQueue). The boards listed every version
 * as its own row, newest first, with nothing on the row to say it was the second
 * try — so a trainer could grade the superseded copy, and «save and go to the
 * next» opened a row that was not at the top.
 *
 * Now: the newest version of each participant's hand-in is listed unless the
 * trainer asks for the earlier ones (`?versions=all`), the number tucked away is
 * said on the page, rows waiting for a mark come first and oldest first — the
 * order the queue walks — and each row names its assignment and its version.
 *
 * Nothing here records, changes or hides a mark, and no version is deleted: this
 * decides which rows a list shows. The definition of "newest" is ONE scope
 * (`newestVersionOnly`) that GradingQueue uses too.
 *
 * @see BR-19, BR-23 · FR-ASGN-29 · PRD §9.11.3, §9.15 · D-136, D-143
 */

use App\Services\Grading\GradingQueue;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-02 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60]);
    $this->trainer = makeTrainer($this->cohort);
    $this->assignment = makeAssignment($this->cohort, ['title' => 'Assignment One']);
    $this->other = makeAssignment($this->cohort, ['title' => 'Assignment Two']);
    $this->sara = makeParticipant($this->cohort);
    $this->omar = makeParticipant($this->cohort);
});

/** The submission ids the board lists, in order. */
function boardRows(object $test, array $query = []): array
{
    Auth::forgetGuards();

    $rows = $test->actingAs($test->trainer)
        ->get(route('trainer.submissions', $query + ['cohort' => $test->cohort->id]))
        ->assertOk()
        ->viewData('rows');

    return collect($rows->items())->map(fn ($row): string => (string) $row->id)->all();
}

it('BR-19: اللوحة تعرض أحدث نسخة من كل تسليم وتخفي السابقة', function (): void {
    $v1 = makeSubmission($this->assignment, $this->sara, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
    $v2 = makeSubmission($this->assignment, $this->sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-20 10:00:00')]);
    $omar = makeSubmission($this->assignment, $this->omar);

    expect(boardRows($this))->toContain($v2->id, $omar->id)
        ->and(boardRows($this))->not->toContain($v1->id);
});

it('BR-19: `versions=all` تعرض كل النسخ ولا تحذف شيئًا', function (): void {
    $v1 = makeSubmission($this->assignment, $this->sara, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
    $v2 = makeSubmission($this->assignment, $this->sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-20 10:00:00')]);

    expect(boardRows($this, ['versions' => 'all']))->toContain($v1->id, $v2->id)
        ->and($v1->fresh())->not->toBeNull();
});

it('BR-19: الأحدث تُحسب لكل مهمة على حدة — تسليم سارة في مهمة أخرى لا يُخفي هذه', function (): void {
    $one = makeSubmission($this->assignment, $this->sara, ['version' => 1]);
    $two = makeSubmission($this->other, $this->sara, ['version' => 3]);

    expect(boardRows($this))->toContain($one->id, $two->id);
});

it('BR-19: الصفحة تقول كم نسخة سابقة مخفية، وتعرض زرّ إظهارها، وبعد الإظهار تقول إن الكل معروض', function (): void {
    makeSubmission($this->assignment, $this->sara, ['version' => 1]);
    makeSubmission($this->assignment, $this->sara, ['version' => 2]);
    Auth::forgetGuards();

    $default = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(e((string) trans_choice('trainer.submissions.versions_hidden', 1, ['count' => 1])), false)
        ->assertSee(e((string) __('trainer.submissions.versions_show')), false)
        ->assertSee('versions=all', false);

    expect($default->viewData('hiddenVersions'))->toBe(1);

    Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id, 'versions' => 'all']))
        ->assertOk()
        ->assertSee(e((string) __('trainer.submissions.versions_all_shown')), false)
        ->assertSee(e((string) __('trainer.submissions.versions_hide')), false);
});

it('BR-19: لوحة بلا نتائج بعد التصفية تقول أيضًا كم نسخة مخفية — لا تختفي الملاحظة مع الصفوف', function (): void {
    // V1 is graded, V2 (newest) is not: `status=graded` finds nothing on the default
    // board, because the graded copy is the hidden one.
    $v1 = makeSubmission($this->assignment, $this->sara, ['version' => 1, 'status' => 'graded']);
    makeSubmission($this->assignment, $this->sara, ['version' => 2, 'status' => 'submitted']);
    Auth::forgetGuards();

    $page = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id, 'status' => 'graded']))
        ->assertOk()
        ->assertSee(e((string) trans_choice('trainer.submissions.versions_hidden', 1, ['count' => 1])), false)
        ->assertSee(e((string) __('trainer.submissions.versions_show')), false);

    expect(collect($page->viewData('rows')->items()))->toHaveCount(0)
        ->and(boardRows($this, ['status' => 'graded', 'versions' => 'all']))->toBe([$v1->id]);
});

it('BR-19: لا ملاحظة ولا زرّ حين لا نسخ سابقة', function (): void {
    makeSubmission($this->assignment, $this->sara);
    Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertDontSee('versionnote', false);
});

it('BR-19: قيمة `versions` خارج «all» تُهمَل — لا تكشف سوى ما تكشفه القائمة الافتراضية', function (): void {
    $v1 = makeSubmission($this->assignment, $this->sara, ['version' => 1]);
    $v2 = makeSubmission($this->assignment, $this->sara, ['version' => 2]);

    expect(boardRows($this, ['versions' => '1']))->toContain($v2->id)->not->toContain($v1->id)
        ->and(boardRows($this, ['versions' => ['all']]))->toContain($v2->id)->not->toContain($v1->id);
});

it('FR-ASGN-29: البانتظار أولًا والأقدم أولًا ثم المُقيَّم — وأول الصف هو ما يفتحه «احفظ وانتقل للتالي»', function (): void {
    $graded = makeSubmission($this->assignment, $this->sara, ['submitted_at' => riyadhAt('2026-10-01 10:00:00')]);
    makeEvaluation('assignment', $graded->id, $this->sara, 7, ['evaluated_by' => $this->trainer->id]);
    $newer = makeSubmission($this->assignment, $this->omar, ['submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
    $older = makeSubmission($this->assignment, makeParticipant($this->cohort), ['submitted_at' => riyadhAt('2026-10-10 10:00:00')]);

    expect(boardRows($this))->toBe([$older->id, $newer->id, $graded->id]);

    // …and that first row is the one the queue would open next from any other.
    expect(app(GradingQueue::class)->nextAssignmentSubmission($graded)?->id)->toBe($older->id);
});

it('FR-ASGN-29: تسليمان بالثانية نفسها يُرتَّبان بالمعرّف — لا بترتيب الإدخال ولا بما تختاره قاعدة البيانات', function (): void {
    $at = riyadhAt('2026-10-19 10:00:00');

    // Inserted in DESCENDING id order, so the insertion order (which SQLite would
    // return for an unstable sort) is the OPPOSITE of the order the board promises.
    $high = makeSubmission($this->assignment, $this->sara, ['submitted_at' => $at, 'id' => '00000000-0000-7000-8000-000000000002']);
    $low = makeSubmission($this->assignment, $this->omar, ['submitted_at' => $at, 'id' => '00000000-0000-7000-8000-000000000001']);

    expect(boardRows($this))->toBe([$low->id, $high->id]);
});

it('FR-ASGN-29: «احفظ وانتقل للتالي» يبقي `versions=all` في الوجهة، ولا يحمل غير القيمة الدقيقة', function (): void {
    $first = makeSubmission($this->assignment, $this->sara, ['submitted_at' => riyadhAt('2026-10-19 08:00:00')]);
    $next = makeSubmission($this->assignment, $this->omar, ['submitted_at' => riyadhAt('2026-10-19 09:00:00')]);
    $body = ['score' => 8, 'feedback' => 'Clear structure, but the evaluation section needs more depth.', 'next' => 1];

    $carried = ['submission' => $first->id, 'cohort' => $this->cohort->id, 'versions' => 'all'];

    $this->actingAs($this->trainer)
        ->post(route('trainer.submissions.grade', $carried), $body)
        ->assertRedirect(route('trainer.submissions', [
            'cohort' => $this->cohort->id,
            'assignment' => $this->assignment->id,
            'versions' => 'all',
            'submission' => $next->id,
        ]));

    Auth::forgetGuards();
    $other = makeSubmission($this->assignment, makeParticipant($this->cohort), ['submitted_at' => riyadhAt('2026-10-19 11:00:00')]);

    $location = $this->actingAs($this->trainer)
        ->post(route('trainer.submissions.grade', ['submission' => $next->id, 'cohort' => $this->cohort->id, 'versions' => '1']), $body)
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->not->toContain('versions=')
        ->and($location)->toContain('submission='.$other->id);
});

it('BR-19: النسخة الأقدم لا يُعرض لها «تقييم» افتراضيًا، وصف النسخة الثانية يحمل «النسخة 2» واسم المهمة', function (): void {
    makeSubmission($this->assignment, $this->sara, ['version' => 1]);
    makeSubmission($this->assignment, $this->sara, ['version' => 2]);
    Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(e((string) __('trainer.submissions.version_n', ['n' => 2])), false)
        ->assertDontSee(e((string) __('trainer.submissions.version_n', ['n' => 1])), false)
        ->assertSee('Assignment One');
});

it('BR-19: ملف التصدير يبقى بكل النسخ — له عمود للنسخة', function (): void {
    makeSubmission($this->assignment, $this->sara, ['version' => 1]);
    makeSubmission($this->assignment, $this->sara, ['version' => 2]);
    Auth::forgetGuards();

    $csv = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions.export', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->getContent();

    expect(substr_count((string) $csv, "\r\n"))->toBe(3); // header + two versions
});

it('FR-ASGN-29: نموذج التقييم يحمل `versions=all` في عنوانه فيعود المدرب إلى اللوحة نفسها', function (): void {
    $submission = makeSubmission($this->assignment, $this->sara);
    Auth::forgetGuards();

    $action = route('trainer.submissions.grade', [
        'submission' => $submission->id,
        'cohort' => $this->cohort->id,
        'versions' => 'all',
    ]);

    $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id, 'versions' => 'all', 'submission' => $submission->id]))
        ->assertOk()
        ->assertSee('action="'.e($action).'"', false);
});

it('FR-ASGN-29: بلا `versions` لا يحمل عنوان النموذج شيئًا منها', function (): void {
    $submission = makeSubmission($this->assignment, $this->sara);
    Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id, 'submission' => $submission->id]))
        ->assertOk()
        ->assertSee('action="'.e(route('trainer.submissions.grade', ['submission' => $submission->id, 'cohort' => $this->cohort->id])).'"', false);
});

it('BR-23: تسليم دفعة أخرى لا يظهر في لوحة هذا المدرب بأي قيمة لـ versions', function (): void {
    $foreignCohort = makeCohort();
    $foreignAssignment = makeAssignment($foreignCohort);
    $foreign = makeSubmission($foreignAssignment, makeParticipant($foreignCohort));

    expect(boardRows($this, ['versions' => 'all']))->not->toContain($foreign->id);
});

/*
|--------------------------------------------------------------------------
| The final-project board says the same thing
|--------------------------------------------------------------------------
*/

function projectRows(object $test, array $query = []): array
{
    Auth::forgetGuards();

    $rows = $test->actingAs($test->trainer)
        ->get(route('trainer.finalProject', $query + ['cohort' => $test->cohort->id]))
        ->assertOk()
        ->viewData('submissions');

    return $rows->map(fn ($row): string => (string) $row['id'])->all();
}

it('BR-19: لوحة المشروع الختامي تعرض أحدث نسخة، و`versions=all` تعرض الكل', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $v1 = makeProjectSubmission($project, $this->sara, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-30 10:00:00')]);
    $v2 = makeProjectSubmission($project, $this->sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-31 10:00:00')]);

    expect(projectRows($this))->toBe([$v2->id])
        ->and(projectRows($this, ['versions' => 'all']))->toBe([$v1->id, $v2->id]);
});

it('BR-19: رابط تقييم نسخة سابقة من المشروع الختامي ما زال يفتح لوحتها', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $v1 = makeProjectSubmission($project, $this->sara, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-30 10:00:00')]);
    makeProjectSubmission($project, $this->sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-31 10:00:00')]);

    expect(projectRows($this, ['grade' => $v1->id]))->toContain($v1->id);
});

it('FR-ASGN-29: المشروع الختامي — البانتظار أولًا والأقدم أولًا', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $graded = makeProjectSubmission($project, $this->sara, ['submitted_at' => riyadhAt('2026-10-01 10:00:00')]);
    makeEvaluation('final_project', $graded->id, $this->sara, 30, ['evaluated_by' => $this->trainer->id]);
    $newer = makeProjectSubmission($project, $this->omar, ['submitted_at' => riyadhAt('2026-10-31 10:00:00')]);
    $older = makeProjectSubmission($project, makeParticipant($this->cohort), ['submitted_at' => riyadhAt('2026-10-20 10:00:00')]);

    expect(projectRows($this))->toBe([$older->id, $newer->id, $graded->id]);
});

it('BR-19: عدّاد المخفي في المشروع الختامي لا يعدّ نسخة سابقة معروضة لأنها مفتوحة في اللوحة', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $v1 = makeProjectSubmission($project, $this->sara, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-30 10:00:00')]);
    makeProjectSubmission($project, $this->sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-31 10:00:00')]);
    Auth::forgetGuards();

    $page = $this->actingAs($this->trainer)
        ->get(route('trainer.finalProject', ['cohort' => $this->cohort->id, 'grade' => $v1->id]))
        ->assertOk();

    // Both rows are listed (v2 as the newest, v1 because its panel is open), so
    // nothing is hidden and the page must not claim otherwise.
    expect($page->viewData('submissions'))->toHaveCount(2)
        ->and($page->viewData('hiddenVersions'))->toBe(0);
});

it('BR-23: معرّف تسليم من مشروع دفعة أخرى في `?grade=` لا يفتح لوحة ولا يُدخل صفًّا في اللوحة', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $mine = makeProjectSubmission($project, $this->sara);

    $foreignCohort = makeCohort();
    $foreignProject = makeFinalProject($foreignCohort, ['is_unlocked' => true]);
    $foreign = makeProjectSubmission($foreignProject, makeParticipant($foreignCohort));

    expect(projectRows($this, ['grade' => $foreign->id]))->toBe([$mine->id]);

    Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->get(route('trainer.finalProject', ['cohort' => $this->cohort->id, 'grade' => $foreign->id]))
        ->assertOk()
        ->assertViewHas('selected', null);
})->group('authz');

it('FR-ASGN-29: المشروع الختامي — «احفظ وانتقل للتالي» يبقي `versions=all` أيضًا', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $first = makeProjectSubmission($project, $this->sara, ['submitted_at' => riyadhAt('2026-10-30 10:00:00')]);
    $next = makeProjectSubmission($project, $this->omar, ['submitted_at' => riyadhAt('2026-10-31 10:00:00')]);

    $this->actingAs($this->trainer)
        ->post(route('trainer.finalProject.grade', ['submission' => $first->id, 'versions' => 'all']), [
            'score' => 30,
            'feedback' => 'Clear structure, but the evaluation section needs more depth.',
            'next' => 1,
        ])
        ->assertRedirect(route('trainer.finalProject', [
            'cohort' => $this->cohort->id,
            'versions' => 'all',
            'grade' => $next->id,
        ]));
});

it('BR-19: تعريف «الأحدث» نص واحد — الطابور واللوحة يستعملان النطاق نفسه', function (): void {
    $queue = (string) file_get_contents(app_path('Services/Grading/GradingQueue.php'));
    $board = (string) file_get_contents(app_path('Http/Controllers/Trainer/SubmissionController.php'));
    $project = (string) file_get_contents(app_path('Http/Controllers/Trainer/FinalProjectController.php'));

    expect(substr_count($queue, 'newestVersionOnly()'))->toBe(2)
        ->and($queue)->not->toContain('newer.version')
        ->and($board)->toContain('newestVersionOnly()')
        ->and($project)->toContain('newestVersionOnly()');
});

it('BR-19: نطاق «الأحدث» يعمل على استعلام باسم مستعار — يربط بالصف الخارجي لا باسم الجدول', function (): void {
    $v1 = makeSubmission($this->assignment, $this->sara, ['version' => 1]);
    $v2 = makeSubmission($this->assignment, $this->sara, ['version' => 2]);

    $aliased = App\Models\Submission::query()->from('submissions as s')->newestVersionOnly();

    expect($aliased->toSql())->toContain('"s"."user_id"')
        ->and($aliased->pluck('s.id')->all())->toBe([$v2->id])
        ->and(App\Models\Submission::query()->newestVersionOnly()->pluck('id')->all())->toBe([$v2->id])
        ->and($v1->fresh())->not->toBeNull();
});

it('BR-19: مع `versions=all` كل صف يقول أيّ نسخة هو، والنسخة التي أُعيد التسليم بعدها تقول ذلك ولا تحمل زرّ التقييم الأساسي', function (): void {
    makeSubmission($this->assignment, $this->sara, ['version' => 1]);
    makeSubmission($this->assignment, $this->sara, ['version' => 2]);
    Auth::forgetGuards();

    $page = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id, 'versions' => 'all']))
        ->assertOk()
        ->assertSee(e((string) __('trainer.submissions.version_n', ['n' => 1])), false)
        ->assertSee(e((string) __('trainer.submissions.version_n', ['n' => 2])), false)
        ->assertSee(e((string) __('trainer.submissions.superseded')), false);

    $rows = collect($page->viewData('rows')->items());

    expect($rows->where('version', 1)->first()['isSuperseded'])->toBeTrue()
        ->and($rows->where('version', 2)->first()['isSuperseded'])->toBeFalse();

    // The default board never shows the mark: everything on it is the newest.
    Auth::forgetGuards();

    $default = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->viewData('rows');

    expect(collect($default->items())->pluck('isSuperseded')->unique()->all())->toBe([false]);
});

it('BR-19: لوحة المشروع الختامي مع `versions=all` تعلّم النسخة السابقة أيضًا', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    makeProjectSubmission($project, $this->sara, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-30 10:00:00')]);
    makeProjectSubmission($project, $this->sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-31 10:00:00')]);
    Auth::forgetGuards();

    $rows = $this->actingAs($this->trainer)
        ->get(route('trainer.finalProject', ['cohort' => $this->cohort->id, 'versions' => 'all']))
        ->assertOk()
        ->viewData('submissions');

    expect($rows->where('version', 1)->first()['isSuperseded'])->toBeTrue()
        ->and($rows->where('version', 2)->first()['isSuperseded'])->toBeFalse();
});
