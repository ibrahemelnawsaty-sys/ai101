<?php

declare(strict_types=1);

/**
 * BR-15 … BR-19: the final project lock, and the assignment lifecycle.
 *
 * ASSUMPTIONS declared rather than made silently: the trainer unlocks the final
 * project at `trainer.finalProject.unlock` and manages assignments at
 * `trainer.assignments.store`. PROJECT-CONTRACT.md §10 collapses the trainer area
 * into `trainer.*` and names no leaf routes.
 *
 * CONTRACT CONFLICT worth the product owner's attention: PRD §9.14.1 says the locked
 * tab renders an elegant locked state (a 200), while the acceptance criteria of the
 * same section say a direct request returns 403. The rule that both readings share,
 * and the one BR-16 actually states, is that no project content reaches the browser.
 * That is what is asserted below, under either status code.
 *
 * PRD §9.11.2 refuses an empty hand-in on the server, not merely by greying out
 * the button, so every submission below carries a GitHub link.
 *
 * @see BR-15, BR-16, BR-17, BR-18, BR-19 · PRD §9.11, §9.14
 */

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\ProjectSubmission;
use App\Models\Submission;

/** A string that exists nowhere else, so finding it in a response body means a leak. */
const PROJECT_BRIEF_CANARY = 'CANARY-BRIEF-8Xq2';
const PROJECT_REQUIREMENTS_CANARY = 'CANARY-REQS-4Lp9';

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-19 12:00:00'));

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);

    $this->project = makeFinalProject($this->cohort, [
        'is_unlocked' => false,
        'brief' => PROJECT_BRIEF_CANARY,
        'requirements' => PROJECT_REQUIREMENTS_CANARY,
    ]);
});

/*
|--------------------------------------------------------------------------
| BR-15, BR-16 — the lock is on the server, and the content never ships
|--------------------------------------------------------------------------
*/

it('BR-15: تبويب المشروع الختامي مقفل حتى يفعّله المدرب والقفل مفروض على الخادم', function (): void {
    $response = $this->actingAs($this->participant)->get(route('finalProject'));

    expect($response->status())->toBeIn([200, 403]);

    // Submitting while locked is refused whatever the tab renders. PRD §9.14.1's
    // acceptance criteria fix the shape of that refusal at 403 — an
    // authorisation refusal, not a validation bounce — and nothing may be
    // stored under either reading.
    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), [
            'description' => 'An attempt to submit before the tab was unlocked.',
            'github_url' => 'https://github.com/athar-trainee/ai101-final',
        ])
        ->assertForbidden();

    expect(ProjectSubmission::query()->count())->toBe(0);
});

it('BR-16: محتوى المشروع الختامي لا يُرسل للمتصفح قبل التفعيل', function (): void {
    $response = $this->actingAs($this->participant)->get(route('finalProject'));

    $body = $response->getContent();

    expect($body)->not->toContain(PROJECT_BRIEF_CANARY)
        ->and($body)->not->toContain(PROJECT_REQUIREMENTS_CANARY);
});

it('BR-16: لوحة التحكم كاملة لا تسرب دليل المشروع قبل التفعيل', function (): void {
    foreach ([route('dashboard'), route('participant.journey'), route('assignments.index')] as $url) {
        $body = $this->actingAs($this->participant)->get($url)->getContent();

        expect($body)->not->toContain(PROJECT_BRIEF_CANARY)
            ->and($body)->not->toContain(PROJECT_REQUIREMENTS_CANARY);
    }
});

it('BR-15: المدرب يفعّل التبويب فيصل المحتوى ويُسجَّل التفعيل في سجل التدقيق', function (): void {
    assertAccepted($this->actingAs($this->trainer)->put(route('trainer.finalProject.unlock', $this->project)));

    $fresh = $this->project->fresh();

    expect($fresh->is_unlocked)->toBeTrue()
        ->and($fresh->unlocked_by)->toBe($this->trainer->id)
        ->and($fresh->unlocked_at)->not->toBeNull();

    expect(AuditLog::query()
        ->where('entity_type', 'final_project')
        ->where('entity_id', $this->project->id)
        ->where('actor_id', $this->trainer->id)
        ->count())->toBe(1);

    $this->actingAs($this->participant)
        ->get(route('finalProject'))
        ->assertOk()
        ->assertSee(PROJECT_BRIEF_CANARY, escape: false);
});

it('BR-15: المتدرب لا يستطيع تفعيل تبويب المشروع بنفسه', function (): void {
    $this->actingAs($this->participant)
        ->put(route('trainer.finalProject.unlock', $this->project))
        ->assertForbidden();

    expect($this->project->fresh()->is_unlocked)->toBeFalse();
});

it('BR-15: التفعيل يُشعر كل متدربي الدفعة', function (): void {
    $second = makeParticipant($this->cohort);
    Notification::query()->delete();

    $this->actingAs($this->trainer)->put(route('trainer.finalProject.unlock', $this->project));

    foreach ([$this->participant, $second] as $trainee) {
        expect(Notification::query()->where('user_id', $trainee->id)->count())->toBeGreaterThan(0);
    }
});

/*
|--------------------------------------------------------------------------
| BR-17 — the trainer owns the assignment definition
|--------------------------------------------------------------------------
*/

it('BR-17: المدرب هو من يحدد المهمة وإجباريتها ودرجتها وموعدها', function (): void {
    $week = makeWeek($this->cohort, 1);

    assertAccepted($this->actingAs($this->trainer)->post(route('trainer.assignments.store'), [
        'cohort_id' => $this->cohort->id,
        'week_id' => $week->id,
        'title' => 'Week one deliverable',
        'description' => 'Submit the notebook and a short write-up.',
        'is_mandatory' => true,
        'max_score' => 15,
        'due_at' => riyadhAt('2026-10-25 23:59:00')->toDateTimeString(),
        'allow_late' => false,
        'status' => 'published',
    ]));

    $assignment = Assignment::query()->sole();

    expect($assignment->is_mandatory)->toBeTrue()
        ->and((int) $assignment->max_score)->toBe(15)
        ->and($assignment->created_by)->toBe($this->trainer->id);
});

it('BR-17: المتدرب لا ينشئ مهمة', function (): void {
    $this->actingAs($this->participant)
        ->post(route('trainer.assignments.store'), [
            'cohort_id' => $this->cohort->id,
            'title' => 'Self-assigned work',
            'max_score' => 10,
            'due_at' => riyadhAt('2026-10-25 23:59:00')->toDateTimeString(),
        ])
        ->assertForbidden();

    expect(Assignment::query()->count())->toBe(0);
});

it('BR-17: مهمة بحالة مسودة لا تظهر للمتدرب', function (): void {
    $draft = makeAssignment($this->cohort, ['status' => 'draft', 'title' => 'CANARY-DRAFT-TITLE']);

    $body = $this->actingAs($this->participant)->get(route('assignments.index'))->getContent();

    expect($body)->not->toContain('CANARY-DRAFT-TITLE');

    $this->actingAs($this->participant)
        ->get(route('assignments.show', $draft))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| BR-18 — the deadline, at ±1 second
|--------------------------------------------------------------------------
*/

it('BR-18: التسليم في الثانية الأخيرة قبل الموعد ليس متأخرًا', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    $assignment = makeAssignment($this->cohort, ['due_at' => $due, 'allow_late' => false]);

    freezeAt($due->subSecond());

    assertAccepted($this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one',
        'note' => 'Submitted with one second to spare.',
    ]));

    expect(Submission::query()->sole()->is_late)->toBeFalse();
});

it('BR-18: التسليم عند الموعد بالضبط ليس متأخرًا', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    $assignment = makeAssignment($this->cohort, ['due_at' => $due, 'allow_late' => false]);

    freezeAt($due);

    assertAccepted($this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one',
        'note' => 'Submitted exactly on the deadline.',
    ]));

    expect(Submission::query()->sole()->is_late)->toBeFalse();
});

it('BR-18: التسليم بعد الموعد بثانية يُرفض حين لا تسمح المهمة بالتأخير', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    $assignment = makeAssignment($this->cohort, ['due_at' => $due, 'allow_late' => false]);

    freezeAt($due->addSecond());

    assertRefused($this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one',
        'note' => 'One second too late.',
    ]));

    expect(Submission::query()->count())->toBe(0);
});

it('BR-18: التسليم بعد الموعد بثانية يُوسم متأخرًا حين تسمح المهمة بالتأخير', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    $assignment = makeAssignment($this->cohort, ['due_at' => $due, 'allow_late' => true]);

    freezeAt($due->addSecond());

    assertAccepted($this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one',
        'note' => 'One second late, but late submissions are allowed.',
    ]));

    expect(Submission::query()->sole()->is_late)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| BR-19 — versions are kept, never overwritten
|--------------------------------------------------------------------------
*/

it('BR-19: إعادة التسليم قبل الموعد تحفظ الإصدار السابق ولا تحذفه', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    $assignment = makeAssignment($this->cohort, ['due_at' => $due, 'allow_late' => false]);

    freezeAt($due->subHours(5));
    $this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one',
        'note' => 'First attempt at the deliverable.',
    ]);

    freezeAt($due->subHour());
    $this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one-v2',
        'note' => 'Second attempt, after feedback from a peer.',
    ]);

    $versions = Submission::query()
        ->where('assignment_id', $assignment->id)
        ->where('user_id', $this->participant->id)
        ->orderBy('version')
        ->get();

    expect($versions)->toHaveCount(2)
        ->and((int) $versions[0]->version)->toBe(1)
        ->and((int) $versions[1]->version)->toBe(2)
        ->and($versions[0]->note)->toContain('First attempt');
});

it('BR-19: رقم الإصدار يزيد ولا يعود للخلف أبدًا', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    $assignment = makeAssignment($this->cohort, ['due_at' => $due, 'allow_late' => false]);

    $seen = [];

    foreach ([9, 7, 5] as $hoursBefore) {
        freezeAt($due->subHours($hoursBefore));
        $this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
            'github_url' => 'https://github.com/athar-trainee/week-one',
            'note' => 'Revision submitted '.$hoursBefore.' hours before the deadline.',
        ]);

        $seen[] = (int) Submission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $this->participant->id)
            ->max('version');
    }

    expect($seen)->toBe([1, 2, 3]);
});

it('BR-19: إعادة تسليم متدرب لا تمس إصدارات متدرب آخر', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');
    $assignment = makeAssignment($this->cohort, ['due_at' => $due, 'allow_late' => false]);
    $other = makeParticipant($this->cohort);

    freezeAt($due->subHours(3));
    $this->actingAs($other)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/other-trainee/week-one',
        'note' => 'The other trainee submitted once.',
    ]);
    $this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one',
        'note' => 'This trainee submitted once as well.',
    ]);

    freezeAt($due->subHours(2));
    $this->actingAs($this->participant)->post(route('assignments.submit', $assignment), [
        'github_url' => 'https://github.com/athar-trainee/week-one-v2',
        'note' => 'And then revised the submission.',
    ]);

    expect(Submission::query()->where('user_id', $other->id)->count())->toBe(1)
        ->and(Submission::query()->where('user_id', $this->participant->id)->count())->toBe(2);
});
