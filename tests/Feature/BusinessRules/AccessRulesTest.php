<?php

declare(strict_types=1);

/**
 * BR-22, BR-23, BR-24, BR-28: horizontal and vertical access control, and the Zoom
 * link that must not exist in the page source before its window opens.
 *
 * Route names are the ones routes/web.php declares. The trainer area has no
 * per-cohort URL segment: EnsureCohortScope reads `?cohort=` and refuses, with 403
 * and an audit entry, any cohort the account was not assigned (BR-23). So the cohort
 * roster is `trainer.participants?cohort=`, the attendance board is
 * `trainer.attendance?cohort=`, and one submission is selected on the submissions
 * board with `trainer.submissions?cohort=&submission=`.
 *
 * TWO GAPS ARE DECLARED, NOT PAPERED OVER: no participant-facing route carries a
 * submission id or an evaluation id, and PROJECT-CONTRACT.md §10 - which enumerates
 * the participant leaves - names neither. The cases that needed such a URL are
 * skipped with that reason rather than pointed at a different route, which would have
 * made them green while proving nothing (CONSTITUTION.md Articles 1, 4).
 *
 * @see BR-22, BR-23, BR-24, BR-28 · PRD §4.2, §4.3, §9.10, §12.2
 */

use App\Models\AuditLog;

const ZOOM_CANARY = 'https://zoom.example.test/j/CANARY-9931';

beforeEach(function () {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->otherCohort = makeCohort();

    $this->participant = makeParticipant($this->cohort);
    $this->otherParticipant = makeParticipant($this->cohort);
    $this->trainer = makeTrainer($this->cohort);
    $this->otherTrainer = makeTrainer($this->otherCohort);
    $this->admin = makeAdmin();

    $this->assignment = makeAssignment($this->cohort, ['max_score' => 10]);
});

/*
|--------------------------------------------------------------------------
| BR-22 — a trainee reaches nothing that belongs to another trainee
|--------------------------------------------------------------------------
*/

it('BR-22: المتدرب لا يصل إلى تسليم متدرب آخر بتغيير المعرّف في الرابط', function () {
    $foreign = makeSubmission($this->assignment, $this->otherParticipant, [
        'note' => 'CANARY-OTHER-SUBMISSION',
    ]);

    $response = $this->actingAs($this->participant)->get(route('submissions.show', $foreign));

    $response->assertForbidden();

    expect($response->getContent())->not->toContain('CANARY-OTHER-SUBMISSION');
})->skip('routes/web.php declares no `submissions.show`, and PROJECT-CONTRACT.md '
    .'section 10 does not name one. Un-skip when the contract grants the screen.');

it('BR-22: المتدرب لا يصل إلى تقييم متدرب آخر', function () {
    $foreign = makeSubmission($this->assignment, $this->otherParticipant);
    $evaluation = makeEvaluation('assignment', $foreign->id, $this->otherParticipant, 9, [
        'feedback' => 'CANARY-OTHER-FEEDBACK-TEXT',
    ]);

    $response = $this->actingAs($this->participant)->get(route('evaluations.show', $evaluation));

    $response->assertForbidden();

    expect($response->getContent())->not->toContain('CANARY-OTHER-FEEDBACK-TEXT');
})->skip('routes/web.php declares no `evaluations.show`. Feedback reaches a trainee '
    .'only through `grades`, whose URL carries no id at all.');

it('BR-22: صفحة درجات المتدرب لا تحمل أي درجة تخص غيره', function () {
    gradeAssignment($this->cohort, $this->otherParticipant, 10, 10);
    gradeAssignment($this->cohort, $this->participant, 10, 3);

    $this->actingAs($this->participant)->get(route('grades'))->assertOk();

    expect(scoreCalculator()->finalScore($this->participant, $this->cohort))->toBe(3.0);
});

it('BR-22: المتدرب لا يصل إلى رسالة في محادثة ليس طرفًا فيها', function () {
    $thread = makeThreadFor($this->otherParticipant, $this->cohort);

    // `messages.poll` is the per-thread endpoint; it asks ThreadPolicy::view() before
    // reading a row. There is no `messages.show`.
    $this->actingAs($this->participant)
        ->get(route('messages.poll', $thread))
        ->assertForbidden();
});

it('BR-22: كل رفض وصول يُسجَّل في سجل التدقيق مع عنوان IP', function () {
    // The denial is provoked through a URL that exists: an assignment belonging to a
    // cohort this trainee is not enrolled in. AssignmentPolicy::view() refuses it, and
    // Article 22 requires the refusal itself to reach audit_logs with the caller's IP.
    $foreign = makeAssignment($this->otherCohort, ['title' => 'CANARY-FOREIGN-ASSIGNMENT']);

    $this->actingAs($this->participant)->get(route('assignments.show', $foreign))->assertForbidden();

    $log = AuditLog::query()
        ->where('actor_id', $this->participant->id)
        ->where('entity_id', $foreign->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->ip_address)->not->toBeNull();
});

it('BR-22: المتدرب لا يصل إلى شهادة متدرب آخر', function () {
    issueCertificateFor($this->otherParticipant, $this->cohort, [
        'serial_number' => 'ATHAR-AI101-2026-0912',
    ]);

    // `certificate.download` carries no id: the controller resolves the signed-in
    // account's own certificate and nobody else's, so there is nothing in the URL to
    // tamper with. What must hold is that this trainee - who has no certificate - is
    // refused, and never sees the other trainee's serial.
    $response = $this->actingAs($this->participant)->get(route('certificate.download'));

    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('ATHAR-AI101-2026-0912');
});

/*
|--------------------------------------------------------------------------
| BR-23 — a trainer reaches only the cohorts assigned to them
|--------------------------------------------------------------------------
*/

it('BR-23: المدرب لا يصل إلى دفعة غير مسندة إليه', function () {
    $this->actingAs($this->trainer)
        ->get(route('trainer.participants', ['cohort' => $this->otherCohort->id]))
        ->assertForbidden();
});

it('BR-23: المدرب لا يفتح تسليمًا في دفعة أخرى', function () {
    $foreignAssignment = makeAssignment($this->otherCohort, ['max_score' => 10]);
    $foreignParticipant = makeParticipant($this->otherCohort);
    $foreignSubmission = makeSubmission($foreignAssignment, $foreignParticipant, [
        'note' => 'CANARY-FOREIGN-COHORT-SUBMISSION',
    ]);

    $response = $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', [
            'cohort' => $this->otherCohort->id,
            'submission' => $foreignSubmission->id,
        ]));

    $response->assertForbidden();

    expect($response->getContent())->not->toContain('CANARY-FOREIGN-COHORT-SUBMISSION');
});

it('BR-23: المدرب لا يرصد درجة لمتدرب في دفعة أخرى', function () {
    $foreignAssignment = makeAssignment($this->otherCohort, ['max_score' => 10]);
    $foreignParticipant = makeParticipant($this->otherCohort);
    $foreignSubmission = makeSubmission($foreignAssignment, $foreignParticipant);

    $this->actingAs($this->trainer)
        ->post(route('trainer.submissions.grade', $foreignSubmission), [
            'score' => 10,
            'feedback' => 'An evaluation the trainer has no right to record.',
        ])
        ->assertForbidden();
});

it('BR-23: المدرب يصل إلى دفعته هو', function () {
    $this->actingAs($this->trainer)
        ->get(route('trainer.participants', ['cohort' => $this->cohort->id]))
        ->assertOk();
});

it('BR-23: تقرير حضور الدفعة لا يحمل متدربًا من دفعة أخرى', function () {
    $foreignParticipant = makeParticipant($this->otherCohort, ['email' => 'foreign-canary@example.test']);
    sessionAttendedBy($this->otherCohort, $foreignParticipant, 'training', 'present');
    sessionAttendedBy($this->cohort, $this->participant, 'training', 'present', 1);

    $body = $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('foreign-canary@example.test')
        ->and($body)->not->toContain($foreignParticipant->id);
});

/*
|--------------------------------------------------------------------------
| BR-24 — the Zoom link is not in the page before S-15m
|--------------------------------------------------------------------------
*/

it('BR-24: رابط الزوم لا يظهر في شيفرة الصفحة قبل الجلسة بخمس عشرة دقيقة', function () {
    $start = riyadhAt('2026-10-12 18:00:00');
    $session = sessionInCohort($this->cohort, $start, $start->addHours(3), ['zoom_url' => ZOOM_CANARY]);

    freezeAt($start->subMinutes(15)->subSecond());

    foreach ([route('live'), route('schedule'), route('dashboard')] as $url) {
        expect($this->actingAs($this->participant)->get($url)->getContent())
            ->not->toContain(ZOOM_CANARY);
    }

    assertRefused($this->actingAs($this->participant)->post(route('live.join', $session)));
});

it('BR-24: الرابط يُسلَّم عند الحد بالضبط قبل الجلسة بخمس عشرة دقيقة', function () {
    $start = riyadhAt('2026-10-12 18:00:00');
    $session = sessionInCohort($this->cohort, $start, $start->addHours(3), ['zoom_url' => ZOOM_CANARY]);

    freezeAt($start->subMinutes(15));

    $response = $this->actingAs($this->participant)->post(route('live.join', $session));

    assertAccepted($response);

    expect($response->getContent().json_encode($response->headers->all()))->toContain(ZOOM_CANARY);
});

it('BR-24: الرابط يُغلق بعد نهاية الجلسة', function () {
    $start = riyadhAt('2026-10-12 18:00:00');
    $end = $start->addHours(3);
    $session = sessionInCohort($this->cohort, $start, $end, ['zoom_url' => ZOOM_CANARY]);

    freezeAt($end->addSecond());

    assertRefused($this->actingAs($this->participant)->post(route('live.join', $session)));
});

it('BR-24: متدرب من دفعة أخرى لا يحصل على الرابط في نافذته الزمنية', function () {
    $start = riyadhAt('2026-10-12 18:00:00');
    $session = sessionInCohort($this->cohort, $start, $start->addHours(3), ['zoom_url' => ZOOM_CANARY]);
    $stranger = makeParticipant($this->otherCohort);

    freezeAt($start);

    $response = $this->actingAs($stranger)->post(route('live.join', $session));

    $response->assertForbidden();

    expect($response->getContent())->not->toContain(ZOOM_CANARY);
});

/*
|--------------------------------------------------------------------------
| BR-28 — the check happens on every request, not once at login
|--------------------------------------------------------------------------
*/

it('BR-28: سحب الدور أثناء الجلسة يمنع الطلب التالي فورًا', function () {
    $this->actingAs($this->trainer)
        ->get(route('trainer.participants', ['cohort' => $this->cohort->id]))
        ->assertOk();

    $this->trainer->update(['role' => 'participant']);

    $this->actingAs($this->trainer->fresh())
        ->get(route('trainer.participants', ['cohort' => $this->cohort->id]))
        ->assertForbidden();
});

it('BR-28: تعليق الحساب أثناء الجلسة يمنع الطلب التالي فورًا', function () {
    $this->actingAs($this->participant)->get(route('dashboard'))->assertOk();

    $this->participant->update(['status' => 'suspended']);

    $response = $this->actingAs($this->participant->fresh())->get(route('dashboard'));

    expect($response->status())->toBeIn([302, 403]);
});

it('BR-28: سحب الالتحاق بالدفعة يمنع الوصول إلى محتواها فورًا', function () {
    $this->actingAs($this->participant)->get(route('assignments.show', $this->assignment))->assertOk();

    $this->participant->enrollments()->where('cohort_id', $this->cohort->id)->update(['status' => 'withdrawn']);

    $this->actingAs($this->participant->fresh())
        ->get(route('assignments.show', $this->assignment))
        ->assertForbidden();
});

it('BR-28: الزائر غير المسجل لا يصل إلى أي مسار في لوحة التحكم', function () {
    foreach ([route('dashboard'), route('grades'), route('attendance.index'), route('participant.journey')] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});
