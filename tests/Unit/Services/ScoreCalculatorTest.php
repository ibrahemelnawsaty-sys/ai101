<?php

declare(strict_types=1);

/**
 * Grade arithmetic: 50 for the performance assignments, 50 for the final project,
 * 100 in total, and a pass threshold read from the cohort — never hard-coded.
 *
 * @see BR-11, BR-12, BR-22 · PRD §9.15.1, §9.15.4 · PROJECT-CONTRACT.md §7
 */

use App\Services\Grading\ScoreCalculator;

beforeEach(function () {
    freezeAt(riyadhAt('2026-10-25 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60]);
    $this->participant = makeParticipant($this->cohort);
    $this->calculator = scoreCalculator();
});


it('BR-11: يعلن ثوابت التوزيع كما نص عليها العقد', function () {
    expect(ScoreCalculator::ASSIGNMENTS_TOTAL)->toBe(50)
        ->and(ScoreCalculator::PROJECT_TOTAL)->toBe(50)
        ->and(ScoreCalculator::GRAND_TOTAL)->toBe(100);
});

it('يبدأ المتدرب من صفر قبل رصد أي درجة', function () {
    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(0.0)
        ->and($this->calculator->projectScore($this->participant, $this->cohort))->toBe(0.0)
        ->and($this->calculator->finalScore($this->participant, $this->cohort))->toBe(0.0);
});

it('يجمع درجات المهام المرصودة', function () {
    gradeAssignment($this->cohort, $this->participant, 30, 25);
    gradeAssignment($this->cohort, $this->participant, 20, 15);

    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(40.0);
});

it('يدعم الدرجات العشرية', function () {
    gradeAssignment($this->cohort, $this->participant, 10, 8.5);
    gradeAssignment($this->cohort, $this->participant, 10, 1.25);

    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(9.75);
});

it('لا يحتسب تسليمًا لم يُقيَّم بعد', function () {
    $assignment = makeAssignment($this->cohort, ['max_score' => 20]);
    makeSubmission($assignment, $this->participant);

    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(0.0);
});

it('لا يخصم من المتدرب مهمة اختيارية لم يسلّمها', function () {
    gradeAssignment($this->cohort, $this->participant, 30, 30);
    makeAssignment($this->cohort, ['max_score' => 20, 'is_mandatory' => false]);

    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(30.0);
});

it('BR-11: مجموع درجات المهام لا يتجاوز خمسين حتى لو أخطأ المدرب في التوزيع', function () {
    // The trainer allocated 60 marks across the assignments and awarded 55 of them.
    // PRD §9.15.1 keeps the warning in the trainer dashboard but caps the trainee.
    gradeAssignment($this->cohort, $this->participant, 30, 30);
    gradeAssignment($this->cohort, $this->participant, 30, 25);

    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(50.0);
});

it('BR-22: لا تتسرب درجة متدرب إلى حساب متدرب آخر', function () {
    $other = makeParticipant($this->cohort);

    gradeAssignment($this->cohort, $other, 30, 30);

    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(0.0)
        ->and($this->calculator->assignmentsScore($other, $this->cohort))->toBe(30.0);
});

it('يفصل درجات المتدرب بين دفعتين مختلفتين', function () {
    $otherCohort = makeCohort(['pass_score' => 60]);
    enroll($this->participant, $otherCohort);

    gradeAssignment($this->cohort, $this->participant, 30, 20);
    gradeAssignment($otherCohort, $this->participant, 30, 30);

    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(20.0)
        ->and($this->calculator->assignmentsScore($this->participant, $otherCohort))->toBe(30.0);
});

/*
|--------------------------------------------------------------------------
| The final project — 50 marks
|--------------------------------------------------------------------------
*/

it('BR-11: يحتسب درجة المشروع النهائي من خمسين', function () {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $submission = makeProjectSubmission($project, $this->participant);

    makeEvaluation('final_project', $submission->id, $this->participant, 42.5);

    expect($this->calculator->projectScore($this->participant, $this->cohort))->toBe(42.5);
});

it('BR-12: درجة المشروع لا تتجاوز خمسين', function () {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $submission = makeProjectSubmission($project, $this->participant);

    makeEvaluation('final_project', $submission->id, $this->participant, 50);

    expect($this->calculator->projectScore($this->participant, $this->cohort))->toBe(50.0)
        ->and($this->calculator->projectScore($this->participant, $this->cohort))
        ->toBeLessThanOrEqual((float) ScoreCalculator::PROJECT_TOTAL);
});

it('لا يخلط تقييم المشروع بتقييمات المهام', function () {
    gradeAssignment($this->cohort, $this->participant, 30, 20);

    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $submission = makeProjectSubmission($project, $this->participant);
    makeEvaluation('final_project', $submission->id, $this->participant, 40);

    expect($this->calculator->assignmentsScore($this->participant, $this->cohort))->toBe(20.0)
        ->and($this->calculator->projectScore($this->participant, $this->cohort))->toBe(40.0);
});

/*
|--------------------------------------------------------------------------
| The total, and the pass threshold
|--------------------------------------------------------------------------
*/

it('BR-11: المجموع الكلي هو المهام زائد المشروع ولا يتجاوز مئة', function () {
    gradeAssignment($this->cohort, $this->participant, 50, 45);

    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $submission = makeProjectSubmission($project, $this->participant);
    makeEvaluation('final_project', $submission->id, $this->participant, 48);

    expect($this->calculator->finalScore($this->participant, $this->cohort))->toBe(93.0)
        ->and($this->calculator->finalScore($this->participant, $this->cohort))
        ->toBeLessThanOrEqual((float) ScoreCalculator::GRAND_TOTAL);
});

it('درجة النجاح تُقرأ من الدفعة لا من الكود', function () {
    $strict = makeCohort(['pass_score' => 80]);
    $participant = makeParticipant($strict);

    gradeAssignment($strict, $participant, 50, 40);
    $project = makeFinalProject($strict, ['is_unlocked' => true]);
    $submission = makeProjectSubmission($project, $participant);
    makeEvaluation('final_project', $submission->id, $participant, 35);

    // 75 out of 100 — a pass at 60, a failure at 80.
    expect($this->calculator->finalScore($participant, $strict))->toBe(75.0)
        ->and($this->calculator->passes($participant, $strict))->toBeFalse();
});

it('حد النجاح يُختبر عند ±0.01 من درجة النجاح', function (float $awarded, bool $passes) {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $submission = makeProjectSubmission($project, $this->participant);

    gradeAssignment($this->cohort, $this->participant, 50, 20);
    makeEvaluation('final_project', $submission->id, $this->participant, $awarded - 20);

    expect($this->calculator->finalScore($this->participant, $this->cohort))->toBe($awarded)
        ->and($this->calculator->passes($this->participant, $this->cohort))->toBe($passes);
})->with([
    'أقل من درجة النجاح بمقدار 0.01' => [59.99, false],
    'درجة النجاح بالضبط' => [60.00, true],
    'أعلى من درجة النجاح بمقدار 0.01' => [60.01, true],
]);

it('صفر مطلق لا يجتاز', function () {
    expect($this->calculator->passes($this->participant, $this->cohort))->toBeFalse();
});
