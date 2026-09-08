<?php

declare(strict_types=1);

/**
 * The ten journey steps complete themselves from real data. There is no manual
 * "mark as done" anywhere in the platform, for anyone.
 *
 * CONTRACT GAP, declared rather than assumed silently: PROJECT-CONTRACT.md §9 fixes
 * the ten steps and their completion conditions, and PRD §7.6 gives JourneyStep an
 * `unlock_rule` column, but neither document fixes the vocabulary of that column.
 * This suite proposes and then enforces the following vocabulary; if the product
 * owner rules otherwise, this file and the evaluator change together.
 *
 *   enrollment · intro_attendance · week_completion · project_submission
 *   project_evaluation · closing_attendance · certificate_issued
 *
 * For `week_completion`, related_entity_type = 'week' and related_entity_id = the
 * week row, which is what makes steps 3 to 6 identical in code and distinct in data.
 *
 * @see BR-20, BR-21, BR-22 · PRD §9.7.1 · PROJECT-CONTRACT.md §9
 */

use App\Models\Certificate;
use App\Models\JourneyStep;
use App\Models\UserJourneyState;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
    $this->weeks = collect(range(1, 4))->mapWithKeys(
        fn (int $index): array => [$index => makeWeek($this->cohort, $index)],
    );
    $this->steps = seedJourneySteps($this->cohort, $this->weeks->all());
    $this->evaluator = journeyEvaluator();
});

/*
|--------------------------------------------------------------------------
| Step 1 — BR-20
|--------------------------------------------------------------------------
*/

it('BR-20: خطوة التسجيل مكتملة تلقائيًا لكل متدرب فور تفعيل حسابه والتحاقه', function (): void {
    expect($this->evaluator->isStepComplete($this->participant, $this->steps[1]))->toBeTrue()
        ->and($this->evaluator->completedCount($this->participant, $this->cohort))->toBe(1);
});

it('BR-20: من لم يلتحق بالدفعة لا تكتمل له خطوة التسجيل', function (): void {
    $stranger = makeParticipant();

    expect($this->evaluator->isStepComplete($stranger, $this->steps[1]))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Step 2 — the intro session
|--------------------------------------------------------------------------
*/

it('حضور اللقاء التعريفي يكمل الخطوة الثانية', function (string $status, bool $complete): void {
    sessionAttendedBy($this->cohort, $this->participant, 'intro', $status);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[2]))->toBe($complete);
})->with([
    'حاضر' => ['present', true],
    'متأخر' => ['late', true],
    'غائب' => ['absent', false],
    'غياب بعذر' => ['excused', false],
]);

it('غياب اللقاء التعريفي كليًا يُبقي الخطوة الثانية مقفلة', function (): void {
    sessionAttendedBy($this->cohort, $this->participant, 'intro', null);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[2]))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Steps 3 to 6 — a week is attendance AND mandatory submissions
|--------------------------------------------------------------------------
*/

it('خطوة الأسبوع لا تكتمل بالحضور وحده', function (): void {
    $week = $this->weeks[1];
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 1, $week->id);
    makeAssignment($this->cohort, ['week_id' => $week->id, 'is_mandatory' => true]);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[3]))->toBeFalse();
});

it('خطوة الأسبوع لا تكتمل بالتسليم وحده', function (): void {
    $week = $this->weeks[1];
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'absent', 1, $week->id);
    $assignment = makeAssignment($this->cohort, ['week_id' => $week->id, 'is_mandatory' => true]);
    makeSubmission($assignment, $this->participant);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[3]))->toBeFalse();
});

it('خطوة الأسبوع تكتمل بالحضور والتسليم معًا', function (): void {
    $week = $this->weeks[1];

    foreach ([1, 2, 3] as $day) {
        sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', $day, $week->id);
    }

    $assignment = makeAssignment($this->cohort, ['week_id' => $week->id, 'is_mandatory' => true]);
    makeSubmission($assignment, $this->participant);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[3]))->toBeTrue();
});

it('مهمة اختيارية غير مسلَّمة لا تمنع اكتمال خطوة الأسبوع', function (): void {
    $week = $this->weeks[1];
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 1, $week->id);

    $mandatory = makeAssignment($this->cohort, ['week_id' => $week->id, 'is_mandatory' => true]);
    makeSubmission($mandatory, $this->participant);
    makeAssignment($this->cohort, ['week_id' => $week->id, 'is_mandatory' => false]);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[3]))->toBeTrue();
});

it('غياب يوم واحد من الأسبوع يمنع اكتمال خطوته', function (): void {
    $week = $this->weeks[1];
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 1, $week->id);
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 2, $week->id);
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'absent', 3, $week->id);

    $assignment = makeAssignment($this->cohort, ['week_id' => $week->id, 'is_mandatory' => true]);
    makeSubmission($assignment, $this->participant);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[3]))->toBeFalse();
});

it('اكتمال الأسبوع الأول لا يكمل الأسبوع الثاني', function (): void {
    $week = $this->weeks[1];
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 1, $week->id);
    $assignment = makeAssignment($this->cohort, ['week_id' => $week->id, 'is_mandatory' => true]);
    makeSubmission($assignment, $this->participant);

    // Week 2 has its own session, unattended.
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'absent', 8, $this->weeks[2]->id);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[3]))->toBeTrue()
        ->and($this->evaluator->isStepComplete($this->participant, $this->steps[4]))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Steps 7 to 10
|--------------------------------------------------------------------------
*/

it('تسليم المشروع النهائي يكمل الخطوة السابعة ولا يكمل الثامنة', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    makeProjectSubmission($project, $this->participant);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[7]))->toBeTrue()
        ->and($this->evaluator->isStepComplete($this->participant, $this->steps[8]))->toBeFalse();
});

it('تقييم المشروع يكمل الخطوة الثامنة', function (): void {
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
    $submission = makeProjectSubmission($project, $this->participant);
    makeEvaluation('final_project', $submission->id, $this->participant, 45);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[8]))->toBeTrue();
});

it('حضور الحفل الختامي يكمل الخطوة التاسعة', function (): void {
    sessionAttendedBy($this->cohort, $this->participant, 'closing', 'present', 30);

    expect($this->evaluator->isStepComplete($this->participant, $this->steps[9]))->toBeTrue();
});

it('إصدار الشهادة يكمل الخطوة العاشرة', function (): void {
    expect($this->evaluator->isStepComplete($this->participant, $this->steps[10]))->toBeFalse();

    Certificate::factory()->create([
        'user_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
        'issued_at' => riyadhAt('2026-11-20 10:00:00'),
    ]);

    expect($this->evaluator->isStepComplete($this->participant->fresh(), $this->steps[10]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Aggregate view — locked / current / completed
|--------------------------------------------------------------------------
*/

it('BR-21: الحالة المجمّعة تُظهر خطوة حالية واحدة فقط والباقي مكتمل أو مقفل', function (): void {
    sessionAttendedBy($this->cohort, $this->participant, 'intro', 'present');

    // Each week owns at least one unattended session, so no week step is vacuously
    // complete and step 3 is unambiguously the current one.
    foreach ($this->weeks as $index => $week) {
        sessionAttendedBy($this->cohort, $this->participant, 'training', null, 10 + $index, $week->id);
    }

    $statuses = collect($this->evaluator->statuses($this->participant, $this->cohort))
        ->map(fn ($status): string => is_string($status) ? $status : $status->value);

    expect($statuses->filter(fn (string $s): bool => $s === 'current'))->toHaveCount(1)
        ->and($statuses)->toHaveCount(10);

    $byIndex = collect($this->steps)->map(fn (JourneyStep $step) => $statuses[$step->id]);

    expect($byIndex[1])->toBe('completed')
        ->and($byIndex[2])->toBe('completed')
        ->and($byIndex[3])->toBe('current')
        ->and($byIndex[10])->toBe('locked');
});

it('BR-21: نسبة التقدم تُحسب من الخطوات المكتملة فعلًا', function (): void {
    foreach ($this->weeks as $index => $week) {
        sessionAttendedBy($this->cohort, $this->participant, 'training', null, 10 + $index, $week->id);
    }

    expect($this->evaluator->progressPercent($this->participant, $this->cohort))->toBe(10.0);

    sessionAttendedBy($this->cohort, $this->participant, 'intro', 'present');

    expect($this->evaluator->progressPercent($this->participant->fresh(), $this->cohort))->toBe(20.0);
});

it('BR-21: المزامنة تكتب حالات الخطوات ولا تكرر السطر عند إعادة التشغيل', function (): void {
    sessionAttendedBy($this->cohort, $this->participant, 'intro', 'present');

    $this->evaluator->sync($this->participant, $this->cohort);
    $this->evaluator->sync($this->participant->fresh(), $this->cohort);

    expect(UserJourneyState::query()->where('user_id', $this->participant->id)->count())->toBe(10);

    $second = UserJourneyState::query()
        ->where('user_id', $this->participant->id)
        ->where('journey_step_id', $this->steps[2]->id)
        ->firstOrFail();

    expect(is_string($second->status) ? $second->status : $second->status->value)->toBe('completed');
});

it('BR-22: بيانات متدرب لا تكمل خطوة متدرب آخر', function (): void {
    $other = makeParticipant($this->cohort);

    sessionAttendedBy($this->cohort, $other, 'intro', 'present');

    expect($this->evaluator->isStepComplete($other, $this->steps[2]))->toBeTrue()
        ->and($this->evaluator->isStepComplete($this->participant, $this->steps[2]))->toBeFalse();
});
