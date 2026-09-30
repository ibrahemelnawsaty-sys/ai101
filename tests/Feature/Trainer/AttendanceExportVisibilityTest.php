<?php

declare(strict_types=1);

/**
 * Who is offered the attendance export, and who is refused it (D-131).
 *
 * The owner decided on 28 September 2026 that the coordinator takes the roll and
 * does not take the record out: the export is the trainer's and the general
 * supervisor's. The policy always said so (CohortPolicy::export), but the screen
 * drew the button for every role, so a coordinator was handed a control that
 * answered 403. A button that leads to an error page is the screen lying about
 * what the person may do (art. 5, 18): the server still refuses, and now the
 * screen agrees with it.
 *
 * @see D-131, D-105 · PRD §9.9.7 · CONSTITUTION Articles 5, 18
 */
uses()->group('authz');

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $start = riyadhAt('2026-10-12 18:00:00');
    sessionInCohort($this->cohort, $start, $start->addHours(3));

    $this->query = ['cohort' => $this->cohort->id];
});

it('D-131: المدرّب والمشرف العام يرون زر تصدير الحضور', function (): void {
    foreach ([makeTrainer($this->cohort), makeAdmin()] as $actor) {
        $this->actingAs($actor)
            ->followingRedirects()
            ->get(route('trainer.attendance', $this->query))
            ->assertOk()
            ->assertSee(route('trainer.attendance.export'), false);

        $this->flushSession();
    }
});

it('D-131: المنسّق لا يرى زر تصدير الحضور ويرفضه الخادم بـ403 لو طُلب الرابط مباشرة', function (): void {
    $coordinator = makeCoordinator($this->cohort);

    $this->actingAs($coordinator)
        ->followingRedirects()
        ->get(route('trainer.attendance', $this->query))
        ->assertOk()
        ->assertDontSee(route('trainer.attendance.export'), false);

    $this->actingAs($coordinator)
        ->get(route('trainer.attendance.export', $this->query))
        ->assertForbidden();
});
