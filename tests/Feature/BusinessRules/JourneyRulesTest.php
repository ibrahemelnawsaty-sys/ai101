<?php

declare(strict_types=1);

/**
 * BR-20, BR-21: the journey completes itself from real data, and nothing in the
 * platform lets a trainee mark a step by hand.
 *
 * @see BR-20, BR-21 · PRD §9.7 · PROJECT-CONTRACT.md §9
 */

use App\Models\UserJourneyState;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    freezeAt(riyadhAt('2026-10-20 12:00:00'));

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
    $this->weeks = collect(range(1, 4))->mapWithKeys(
        fn (int $index): array => [$index => makeWeek($this->cohort, $index)]
    );
    $this->steps = seedJourneySteps($this->cohort, $this->weeks->all());
});

it('BR-20: خطوة التسجيل تظهر مكتملة لمتدرب جديد دون أي إجراء منه', function () {
    $this->actingAs($this->participant)
        ->get(route('participant.journey'))
        ->assertOk();

    expect(journeyEvaluator()->isStepComplete($this->participant, $this->steps[1]))->toBeTrue();
});

it('BR-20: عدد الخطوات المعروضة عشر خطوات بالترتيب', function () {
    $this->actingAs($this->participant)
        ->get(route('participant.journey'))
        ->assertOk();

    $statuses = journeyEvaluator()->statuses($this->participant, $this->cohort);

    expect($statuses)->toHaveCount(10);
});

it('BR-21: تسجيل حضور اللقاء التعريفي يحدّث خطوة الرحلة تلقائيًا', function () {
    $start = riyadhAt('2026-10-05 18:00:00');
    $session = sessionInCohort($this->cohort, $start, $start->addHours(3), ['type' => 'intro']);

    expect(journeyEvaluator()->isStepComplete($this->participant, $this->steps[2]))->toBeFalse();

    freezeAt($start);
    assertAccepted($this->actingAs($this->participant)->post(route('attendance.checkIn', $session)));

    expect(journeyEvaluator()->isStepComplete($this->participant->fresh(), $this->steps[2]))->toBeTrue();
});

it('BR-21: تسليم مهمة يحدّث خطوة الأسبوع المرتبطة تلقائيًا', function () {
    $week = $this->weeks[1];
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 1, $week->id);

    $assignment = makeAssignment($this->cohort, [
        'week_id' => $week->id,
        'is_mandatory' => true,
        'due_at' => riyadhAt('2026-10-25 23:59:00'),
    ]);

    expect(journeyEvaluator()->isStepComplete($this->participant, $this->steps[3]))->toBeFalse();

    // PRD §9.11.2 refuses an empty hand-in on the server: a submission carries at
    // least one file or a GitHub link, and the disabled button is only a mirror of
    // that rule. A note alone is not a submission, so the fixture supplies a link.
    freezeAt(riyadhAt('2026-10-24 12:00:00'));
    assertAccepted($this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one',
        'note' => 'The week one deliverable, submitted before the deadline.',
    ]));

    expect(journeyEvaluator()->isStepComplete($this->participant->fresh(), $this->steps[3]))->toBeTrue();
});

it('BR-21: لا يوجد أي مسار يسمح للمتدرب بتعليم خطوة كمكتملة يدويًا', function () {
    // A whitelist check: no named route in the whole application exposes a
    // journey-step write to a participant. The absence of a feature is a
    // requirement here, so it is asserted rather than assumed.
    $writable = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
        ->map(fn ($route): string => (string) $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'journey'))
        ->values();

    expect($writable)->toBeEmpty();
});

it('BR-21: حالة الخطوة لا تُقبل من الطلب حتى لو أُرسلت صراحة', function () {
    $step = $this->steps[9];

    $response = $this->actingAs($this->participant)->get(route('participant.journey', [
        'step' => $step->id,
        'status' => 'completed',
    ]));

    expect($response->status())->toBeIn([200, 302, 404])
        ->and(UserJourneyState::query()
            ->where('user_id', $this->participant->id)
            ->where('journey_step_id', $step->id)
            ->where('status', 'completed')
            ->count())->toBe(0);
});

it('BR-21: خطوات متدرب لا تتأثر ببيانات متدرب آخر في الدفعة نفسها', function () {
    $other = makeParticipant($this->cohort);
    sessionAttendedBy($this->cohort, $other, 'intro', 'present');

    expect(journeyEvaluator()->isStepComplete($other, $this->steps[2]))->toBeTrue()
        ->and(journeyEvaluator()->isStepComplete($this->participant, $this->steps[2]))->toBeFalse();
});

it('BR-21: شريط التقدم يعرض نسبة محسوبة من الخطوات لا قيمة مخزّنة يدويًا', function () {
    sessionAttendedBy($this->cohort, $this->participant, 'intro', 'present');

    foreach ($this->weeks as $index => $week) {
        sessionAttendedBy($this->cohort, $this->participant, 'training', null, 10 + $index, $week->id);
    }

    expect(journeyEvaluator()->progressPercent($this->participant, $this->cohort))->toBe(20.0);

    // Corrupting the stored state must not change the computed answer.
    UserJourneyState::query()
        ->where('user_id', $this->participant->id)
        ->update(['status' => 'completed']);

    expect(journeyEvaluator()->progressPercent($this->participant->fresh(), $this->cohort))->toBe(20.0);
});
