<?php

declare(strict_types=1);

/**
 * G9, horizontal half — changing an id in the URL must return 403, never data.
 *
 * Every case asserts two things: the status is 403, and the response body does not
 * contain the canary planted in the foreign record. A 403 page that still leaks the
 * row in a debug panel or a flash message would pass the first assertion alone.
 *
 * Every URL below is built from a route name that routes/web.php actually declares.
 * Where the trainer area takes its cohort from the query string, the id is passed as
 * `?cohort=` — that is the input EnsureCohortScope reads, and naming a cohort the
 * account was never assigned is precisely the horizontal attempt this file is about
 * (BR-23).
 *
 * TWO GAPS ARE DECLARED, NOT PAPERED OVER. The platform exposes no participant-facing
 * URL that carries a submission id or an evaluation id, so the two cases below cannot
 * be exercised at all and are skipped with that reason rather than retargeted at some
 * other route, which would have made them pass while proving nothing. Adding those
 * screens is a product decision: PROJECT-CONTRACT.md §10 enumerates the participant
 * leaves and names neither, and §10 has to be amended before either exists
 * (CONSTITUTION.md Articles 1, 4).
 *
 * @see BR-22, BR-23 · PRD §12.2, §14.1 · CONSTITUTION.md Article 22
 */

use App\Models\Attendance;

uses()->group('authz');

beforeEach(function () {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->foreignCohort = makeCohort();

    $this->participant = makeParticipant($this->cohort);
    $this->foreignParticipant = makeParticipant($this->foreignCohort);
    $this->peer = makeParticipant($this->cohort);

    $this->trainer = makeTrainer($this->cohort);
    $this->foreignTrainer = makeTrainer($this->foreignCohort);
});

/**
 * Assert that $actor is refused $url and that $canary never reaches the browser.
 */
function assertNoHorizontalAccess(object $test, object $actor, string $url, string $canary): void
{
    $response = $test->actingAs($actor)->get($url);

    expect($response->status())->toBe(403)
        ->and($response->getContent())->not->toContain($canary);
}

it('المتدرب لا يفتح تسليم زميله في الدفعة نفسها', function () {
    $assignment = makeAssignment($this->cohort, ['max_score' => 10]);
    $peerSubmission = makeSubmission($assignment, $this->peer, ['note' => 'CANARY-PEER-NOTE']);

    assertNoHorizontalAccess($this, $this->participant, route('submissions.show', $peerSubmission), 'CANARY-PEER-NOTE');
})->skip('No participant route carries a submission id: routes/web.php has no '
    .'`submissions.show` and PROJECT-CONTRACT.md §10 does not name one. Un-skip when '
    .'the contract grants the screen.');

it('المتدرب لا يفتح تسليم متدرب في دفعة أخرى', function () {
    $assignment = makeAssignment($this->foreignCohort, ['max_score' => 10]);
    $foreignSubmission = makeSubmission($assignment, $this->foreignParticipant, ['note' => 'CANARY-FOREIGN-NOTE']);

    assertNoHorizontalAccess($this, $this->participant, route('submissions.show', $foreignSubmission), 'CANARY-FOREIGN-NOTE');
})->skip('No participant route carries a submission id — see the note above. The '
    .'cohort half of this guarantee is still covered by the foreign-assignment case '
    .'further down this file.');

it('المتدرب لا يفتح تقييم زميله', function () {
    $assignment = makeAssignment($this->cohort, ['max_score' => 10]);
    $peerSubmission = makeSubmission($assignment, $this->peer);
    $evaluation = makeEvaluation('assignment', $peerSubmission->id, $this->peer, 9, [
        'feedback' => 'CANARY-PEER-FEEDBACK',
    ]);

    assertNoHorizontalAccess($this, $this->participant, route('evaluations.show', $evaluation), 'CANARY-PEER-FEEDBACK');
})->skip('No participant route carries an evaluation id: feedback is reached only '
    .'through `grades`, which is scoped to the signed-in account and has no id in its '
    .'URL at all. PROJECT-CONTRACT.md §10 names no `evaluations.show`.');

it('المتدرب لا يفتح محادثة زميله', function () {
    $thread = makeThreadFor($this->peer, $this->cohort, ['title' => 'CANARY-PEER-THREAD']);

    // `messages.poll` is the per-thread endpoint: it asks ThreadPolicy::view() for the
    // thread in the URL before reading a single row. There is no `messages.show`.
    assertNoHorizontalAccess($this, $this->participant, route('messages.poll', $thread), 'CANARY-PEER-THREAD');
});

it('المتدرب لا يحمّل شهادة زميله', function () {
    issueCertificateFor($this->peer, $this->cohort, ['serial_number' => 'ATHAR-AI101-2026-0777']);

    // The route is `certificate.download` and it takes NO id: the controller resolves
    // the signed-in account's own certificate. There is therefore no id to tamper
    // with, and the guarantee reduces to what is asserted here — a participant with
    // no certificate of their own is refused, and the peer's serial never reaches the
    // browser. 404 is a legitimate refusal for a resource that does not exist for
    // this account; 403 is the answer when the policy refuses one that does.
    $response = $this->actingAs($this->participant)->get(route('certificate.download'));

    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('ATHAR-AI101-2026-0777');
});

it('المتدرب لا يفتح مهمة في دفعة ليس ملتحقًا بها', function () {
    $foreignAssignment = makeAssignment($this->foreignCohort, ['title' => 'CANARY-FOREIGN-ASSIGNMENT']);

    assertNoHorizontalAccess(
        $this,
        $this->participant,
        route('assignments.show', $foreignAssignment),
        'CANARY-FOREIGN-ASSIGNMENT'
    );
});

it('المتدرب لا يسجل حضوره في جلسة دفعة أخرى', function () {
    $start = riyadhAt('2026-10-12 18:00:00');
    $foreignSession = sessionInCohort($this->foreignCohort, $start, $start->addHours(3));

    freezeAt($start);

    $this->actingAs($this->participant)
        ->post(route('attendance.checkIn', $foreignSession))
        ->assertForbidden();

    expect(Attendance::query()->count())->toBe(0);
});

it('المتدرب لا يسجل حضوره نيابة عن زميله بإرسال معرّفه', function () {
    $start = riyadhAt('2026-10-12 18:00:00');
    $session = sessionInCohort($this->cohort, $start, $start->addHours(3));

    freezeAt($start);

    $this->actingAs($this->participant)->post(route('attendance.checkIn', $session), [
        'user_id' => $this->peer->id,
    ]);

    expect(Attendance::query()->where('user_id', $this->peer->id)->count())->toBe(0)
        ->and(Attendance::query()->where('user_id', $this->participant->id)->count())->toBe(1);
});

it('المدرب لا يفتح دفعة غير مسندة إليه', function () {
    assertNoHorizontalAccess(
        $this,
        $this->trainer,
        route('trainer.participants', ['cohort' => $this->foreignCohort->id]),
        (string) $this->foreignCohort->id
    );
});

it('المدرب لا يفتح تسليمًا في دفعة أخرى', function () {
    $foreignAssignment = makeAssignment($this->foreignCohort, ['max_score' => 10]);
    $foreignSubmission = makeSubmission($foreignAssignment, $this->foreignParticipant, [
        'note' => 'CANARY-OTHER-COHORT-NOTE',
    ]);

    assertNoHorizontalAccess(
        $this,
        $this->trainer,
        route('trainer.submissions', [
            'cohort' => $this->foreignCohort->id,
            'submission' => $foreignSubmission->id,
        ]),
        'CANARY-OTHER-COHORT-NOTE'
    );
});

it('المدرب لا يرى تقرير حضور دفعة أخرى', function () {
    assertNoHorizontalAccess(
        $this,
        $this->trainer,
        route('trainer.attendance', ['cohort' => $this->foreignCohort->id]),
        (string) $this->foreignParticipant->id
    );
});

it('المدرب لا يفعّل مشروع دفعة أخرى', function () {
    $foreignProject = makeFinalProject($this->foreignCohort);

    $this->actingAs($this->trainer)
        ->put(route('trainer.finalProject.unlock', $foreignProject))
        ->assertForbidden();

    expect($foreignProject->fresh()->is_unlocked)->toBeFalse();
});

it('معرّف غير موجود لا يكشف وجود الموارد من عدمه', function () {
    $missingId = '00000000-0000-4000-8000-000000000000';

    $response = $this->actingAs($this->participant)->get(route('assignments.show', $missingId));

    expect($response->status())->toBeIn([403, 404]);
});

it('المدرب يصل إلى موارد دفعته هو — الضبط المضاد', function () {
    $assignment = makeAssignment($this->cohort, ['max_score' => 10]);
    $submission = makeSubmission($assignment, $this->participant, ['note' => 'CANARY-OWN-COHORT-NOTE']);

    $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', [
            'cohort' => $this->cohort->id,
            'submission' => $submission->id,
        ]))
        ->assertOk()
        ->assertSee('CANARY-OWN-COHORT-NOTE', escape: false);
});
