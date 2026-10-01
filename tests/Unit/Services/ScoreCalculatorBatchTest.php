<?php

declare(strict_types=1);

/**
 * Phase 5 · D-150 — the final score of a whole list of people, asked of the service that owns it.
 *
 * The reports and the dashboard averaged two columns of `enrollments` that nothing writes, so the
 * average was always «nothing». The owner chose option A: the figures are computed when shown,
 * from the services that own them. For the score that means asking `ScoreCalculator` — but its
 * `finalScore()` takes ONE person and costs about seven queries, so a cohort of sixty on a
 * report page would be four hundred. `finalScores()` answers for the whole list in a fixed
 * handful of queries, and it is held to ONE rule: it must give, for every person, exactly what
 * `finalScore()` gives. The tests below put the two side by side over every case the single
 * calculation has a rule for (BR-11, BR-12, BR-19, BR-22).
 *
 * Nothing about HOW a score is worked out changes here; this is the same rule, asked in bulk.
 *
 * @see BR-11, BR-12, BR-19, BR-22 · PRD §9.15.1, §9.15.4 · CONSTITUTION art. 6, art. 19 · D-150
 */

use App\Models\Evaluation;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-25 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60]);
    $this->calculator = scoreCalculator();
});

/** @param  list<App\Models\User>  $people */
function bulkEqualsSingle(object $test, array $people): void
{
    $ids = array_map(static fn ($user): string => (string) $user->id, $people);
    $bulk = $test->calculator->finalScores($ids, $test->cohort);

    expect(array_keys($bulk))->toEqualCanonicalizing($ids);

    foreach ($people as $user) {
        expect($bulk[(string) $user->id])->toBe($test->calculator->finalScore($user, $test->cohort), "person {$user->id}");
    }
}

it('BR-11: لكل شخص في القائمة الدرجة نفسها التي تعطيها finalScore — مهام ومشروع', function (): void {
    $a = makeParticipant($this->cohort);
    $b = makeParticipant($this->cohort);
    $c = makeParticipant($this->cohort);

    gradeAssignment($this->cohort, $a, 30, 25);
    gradeAssignment($this->cohort, $a, 20, 15);
    $project = makeFinalProject($this->cohort);
    $hand = makeProjectSubmission($project, $a);
    makeEvaluation('final_project', $hand->id, $a, 40);

    gradeAssignment($this->cohort, $b, 10, 8.5);

    bulkEqualsSingle($this, [$a, $b, $c]);

    expect($this->calculator->finalScores([$a->id, $b->id, $c->id], $this->cohort))
        ->toBe([(string) $a->id => 80.0, (string) $b->id => 8.5, (string) $c->id => 0.0]);
});

it('BR-19: آخر تقييم لمهمة هو المعتبر ولو تعدّدت النسخ', function (): void {
    $a = makeParticipant($this->cohort);
    $assignment = makeAssignment($this->cohort, ['max_score' => 20]);

    $v1 = makeSubmission($assignment, $a, ['version' => 1]);
    $v2 = makeSubmission($assignment, $a, ['version' => 2]);

    // One evaluation per hand-in (a revision rewrites it): the older copy was marked first.
    makeEvaluation('assignment', $v1->id, $a, 5, ['evaluated_at' => riyadhAt('2026-10-21 10:00:00')]);
    makeEvaluation('assignment', $v2->id, $a, 9, ['evaluated_at' => riyadhAt('2026-10-22 10:00:00')]);

    bulkEqualsSingle($this, [$a]);

    expect($this->calculator->finalScores([$a->id], $this->cohort)[(string) $a->id])->toBe(9.0);
});

it('BR-11: مهمة غير منشورة لا تدخل الحساب، وسقف المهام خمسون وسقف المشروع خمسون والمجموع مئة', function (): void {
    $a = makeParticipant($this->cohort);
    $capped = makeParticipant($this->cohort);

    $draft = makeAssignment($this->cohort, ['max_score' => 10, 'status' => 'draft']);
    makeEvaluation('assignment', makeSubmission($draft, $a)->id, $a, 10);
    gradeAssignment($this->cohort, $a, 10, 4);

    gradeAssignment($this->cohort, $capped, 30, 30);
    gradeAssignment($this->cohort, $capped, 30, 25);
    $project = makeFinalProject($this->cohort);
    makeEvaluation('final_project', makeProjectSubmission($project, $capped)->id, $capped, 55);

    // The fifty-mark cap on assignments matters on its own, before the project is added.
    $overAssigned = makeParticipant($this->cohort);
    gradeAssignment($this->cohort, $overAssigned, 30, 30);
    gradeAssignment($this->cohort, $overAssigned, 30, 25);
    makeEvaluation('final_project', makeProjectSubmission($project, $overAssigned)->id, $overAssigned, 30);

    // And the fifty-mark cap on the project, before it is added to the assignments.
    $overProject = makeParticipant($this->cohort);
    gradeAssignment($this->cohort, $overProject, 20, 20);
    makeEvaluation('final_project', makeProjectSubmission($project, $overProject)->id, $overProject, 55);

    bulkEqualsSingle($this, [$a, $capped, $overAssigned, $overProject]);

    $bulk = $this->calculator->finalScores([$a->id, $capped->id, $overAssigned->id, $overProject->id], $this->cohort);

    expect($bulk[(string) $a->id])->toBe(4.0)
        ->and($bulk[(string) $capped->id])->toBe(100.0)
        ->and($bulk[(string) $overAssigned->id])->toBe(80.0)
        ->and($bulk[(string) $overProject->id])->toBe(70.0);
});

it('BR-12: درجة سالبة لا تُنقص، وآخر تقييم للمشروع هو المعتبر', function (): void {
    $a = makeParticipant($this->cohort);

    gradeAssignment($this->cohort, $a, 20, 12);
    $assignment = makeAssignment($this->cohort, ['max_score' => 20]);
    makeEvaluation('assignment', makeSubmission($assignment, $a)->id, $a, -5);

    $project = makeFinalProject($this->cohort);
    $first = makeProjectSubmission($project, $a, ['version' => 1]);
    $second = makeProjectSubmission($project, $a, ['version' => 2]);
    makeEvaluation('final_project', $first->id, $a, 20, ['evaluated_at' => riyadhAt('2026-11-02 10:00:00')]);
    makeEvaluation('final_project', $second->id, $a, 33, ['evaluated_at' => riyadhAt('2026-11-03 10:00:00')]);

    bulkEqualsSingle($this, [$a]);

    expect($this->calculator->finalScores([$a->id], $this->cohort)[(string) $a->id])->toBe(45.0);
});

it('BR-22: درجات دفعة أخرى ومتدرب آخر لا تتسرب، وتقييم باسم شخص على تسليم غيره لا يُحتسب له', function (): void {
    $a = makeParticipant($this->cohort);
    $b = makeParticipant($this->cohort);

    $other = makeCohort(['pass_score' => 60]);
    enroll($a, $other, 'participant');
    gradeAssignment($other, $a, 30, 30);

    gradeAssignment($this->cohort, $b, 30, 20);

    // An evaluation whose owner is A but which names B's submission: it is not A's mark.
    $assignment = makeAssignment($this->cohort, ['max_score' => 20]);
    $bsSubmission = makeSubmission($assignment, $b);
    Evaluation::factory()->create([
        'entity_type' => 'assignment',
        'entity_id' => $bsSubmission->id,
        'user_id' => $a->id,
        'score' => 19,
        'max_score' => 20,
        'feedback' => str_repeat('n', 24),
        'evaluated_by' => makeUser('trainer')->id,
        'evaluated_at' => riyadhAt('2026-10-21 12:00:00'),
    ]);

    // The same for the final project: B's hand-in carries a mark filed under A.
    $project = makeFinalProject($this->cohort);
    $bsProject = makeProjectSubmission($project, $b);
    Evaluation::factory()->create([
        'entity_type' => 'final_project',
        'entity_id' => $bsProject->id,
        'user_id' => $a->id,
        'score' => 40,
        'max_score' => 50,
        'feedback' => str_repeat('n', 24),
        'evaluated_by' => makeUser('trainer')->id,
        'evaluated_at' => riyadhAt('2026-11-02 12:00:00'),
    ]);

    bulkEqualsSingle($this, [$a, $b]);

    $bulk = $this->calculator->finalScores([$a->id, $b->id], $this->cohort);

    expect($bulk[(string) $a->id])->toBe(0.0)
        ->and($bulk[(string) $b->id])->toBe(20.0);
});

it('D-150: قائمة فارغة تعطي فراغًا، ودفعة بلا مهام ولا مشروع تعطي صفرًا لكل من فيها', function (): void {
    $a = makeParticipant($this->cohort);

    expect($this->calculator->finalScores([], $this->cohort))->toBe([])
        ->and($this->calculator->finalScores([$a->id], $this->cohort))->toBe([(string) $a->id => 0.0]);
});

it('art. 19: عدد الاستعلامات ثابت — لا يزيد بعدد الأشخاص', function (): void {
    $count = function (int $people): int {
        $cohort = makeCohort(['pass_score' => 60]);
        $users = [];

        for ($i = 0; $i < $people; $i++) {
            $user = makeParticipant($cohort);
            gradeAssignment($cohort, $user, 10, 5);
            $users[] = (string) $user->id;
        }

        $project = makeFinalProject($cohort);
        makeEvaluation('final_project', makeProjectSubmission($project, makeParticipant($cohort))->id, makeParticipant($cohort), 10);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->calculator->finalScores($users, $cohort);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(12))->toBe($count(3));
});

it('D-150: دفعة فيها مهمة منشورة ومشروع ولم يسلّم أحد في القائمة شيئًا تعطي صفرًا لكل منهم', function (): void {
    $a = makeParticipant($this->cohort);
    $b = makeParticipant($this->cohort);

    // A published assignment and a final project exist, and nobody on the list has handed in.
    makeAssignment($this->cohort, ['max_score' => 20]);
    makeFinalProject($this->cohort);

    bulkEqualsSingle($this, [$a, $b]);

    expect($this->calculator->finalScores([$a->id, $b->id], $this->cohort))
        ->toBe([(string) $a->id => 0.0, (string) $b->id => 0.0]);
});

it('BR-22: تسليم مشروع في دفعة أخرى وتقييمه لا يدخل درجة هذه الدفعة', function (): void {
    $a = makeParticipant($this->cohort);

    $other = makeCohort(['pass_score' => 60]);
    enroll($a, $other, 'participant');
    makeEvaluation('final_project', makeProjectSubmission(makeFinalProject($other), $a)->id, $a, 40);

    // This cohort has its own final project, which A has not handed in.
    makeFinalProject($this->cohort);

    bulkEqualsSingle($this, [$a]);

    expect($this->calculator->finalScores([$a->id], $this->cohort)[(string) $a->id])->toBe(0.0);
});
