<?php

declare(strict_types=1);

/**
 * Telling a cohort about work: publishing an assignment, and reminding who has
 * not handed it in.
 *
 * WHY THIS SUITE EXISTS
 * Both halves were wrong in ways no test could see (D-68):
 *   · saving a DRAFT e-mailed the whole cohort its title, score and deadline,
 *     and publishing it later told nobody;
 *   · the letter's button pointed at the TRAINER board, a 403 for every
 *     participant who pressed it;
 *   · "remind who has not submitted" could never render, and the endpoint said
 *     "we sent the reminder" and sent nothing.
 * And no in-app writer read the bell switch the preferences screen offers.
 *
 * @see PRD §9.11.3, §9.16, §9.16.1 · FR-NOTIF-13 · FR-ASGN-30 · BR-23 · D-68
 */

use App\Enums\EnrollmentStatus;
use App\Events\AssignmentPublished;
use App\Mail\AtharLetter;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-19 12:00:00'));

    Mail::fake();

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->week = makeWeek($this->cohort, 1);
});

/** The full payload both assignment forms require. */
function assignmentPayload(object $test, array $overrides = []): array
{
    return $overrides + [
        'week_id' => $test->week->id,
        'title' => 'CANARY-TASK',
        'description' => 'Submit the notebook and a short write-up.',
        'is_mandatory' => true,
        'max_score' => 10,
        'due_at' => riyadhAt('2026-10-25 23:59:00')->toDateTimeString(),
        'allow_late' => false,
        'show_github_field' => false,
        'status' => 'published',
    ];
}

function lettersTo(User $user, string $copyKey): int
{
    return Mail::queued(AtharLetter::class, static fn (AtharLetter $letter): bool => $letter->copyKey === $copyKey
        && $letter->hasTo((string) $user->email))->count();
}

function noticesFor(User $user, string $type): int
{
    return Notification::query()->where('user_id', $user->id)->where('type', $type)->count();
}

function withdraw(User $user, object $test): void
{
    Enrollment::query()
        ->where('user_id', $user->id)
        ->where('cohort_id', $test->cohort->id)
        ->update(['status' => EnrollmentStatus::Withdrawn->value]);
}

/*
|--------------------------------------------------------------------------
| FR-NOTIF-13 — a new assignment is announced when it is PUBLISHED
|--------------------------------------------------------------------------
*/

it('FR-NOTIF-13: حفظ مهمة جديدة مسودةً لا يرسل رسالة ولا يكتب إشعارًا', function (): void {
    assertAccepted($this->actingAs($this->trainer)
        ->post(route('trainer.assignments.store'), assignmentPayload($this, ['status' => 'draft'])));

    Mail::assertNotQueued(AtharLetter::class);
    expect(Notification::query()->where('type', 'assignment_published')->count())->toBe(0);
});

it('FR-NOTIF-13: مهمة تُنشأ منشورةً تصل كل متدرّب نشط مرة واحدة على القناتين — لا المنسحب ولا المدرّب', function (): void {
    $withdrawn = makeParticipant($this->cohort);
    withdraw($withdrawn, $this);

    assertAccepted($this->actingAs($this->trainer)
        ->post(route('trainer.assignments.store'), assignmentPayload($this)));

    expect(lettersTo($this->participant, 'emails.assignment_published'))->toBe(1)
        ->and(noticesFor($this->participant, 'assignment_published'))->toBe(1)
        ->and(lettersTo($withdrawn, 'emails.assignment_published'))->toBe(0)
        ->and(noticesFor($withdrawn, 'assignment_published'))->toBe(0)
        ->and(lettersTo($this->trainer, 'emails.assignment_published'))->toBe(0)
        ->and(noticesFor($this->trainer, 'assignment_published'))->toBe(0);
});

it('FR-NOTIF-13: نشر مسودة عبر التعديل يصل الدفعة على القناتين', function (): void {
    $draft = makeAssignment($this->cohort, ['status' => 'draft', 'week_id' => $this->week->id]);

    assertAccepted($this->actingAs($this->trainer)
        ->patch(route('trainer.assignments.update', $draft), assignmentPayload($this)));

    expect(lettersTo($this->participant, 'emails.assignment_published'))->toBe(1)
        ->and(noticesFor($this->participant, 'assignment_published'))->toBe(1);
});

it('FR-NOTIF-13: إعادة حفظ مهمة منشورة لا ترسل شيئًا ثانيًا، ومسودة تبقى مسودة لا ترسل شيئًا', function (): void {
    $published = makeAssignment($this->cohort, ['status' => 'published', 'week_id' => $this->week->id]);
    $draft = makeAssignment($this->cohort, ['status' => 'draft', 'week_id' => $this->week->id]);

    assertAccepted($this->actingAs($this->trainer)
        ->patch(route('trainer.assignments.update', $published), assignmentPayload($this, ['title' => 'CANARY-RENAMED'])));
    assertAccepted($this->actingAs($this->trainer)
        ->patch(route('trainer.assignments.update', $draft), assignmentPayload($this, ['status' => 'draft'])));

    Mail::assertNotQueued(AtharLetter::class);
    expect(Notification::query()->where('type', 'assignment_published')->count())->toBe(0);
});

it('FR-NOTIF-13: زرّ الرسالة ورابط الإشعار يفتحان صفحة المهمة عند المتدرّب لا لوحة المدرّب', function (): void {
    assertAccepted($this->actingAs($this->trainer)
        ->post(route('trainer.assignments.store'), assignmentPayload($this)));

    $assignment = Assignment::query()->sole();
    $expected = route('assignments.show', $assignment);

    /** @var AtharLetter|null $letter */
    $letter = Mail::queued(AtharLetter::class, static fn (AtharLetter $l): bool => $l->copyKey === 'emails.assignment_published')->first();
    $notice = Notification::query()->where('user_id', $this->participant->id)->sole();

    expect($letter?->ctaUrl)->toBe($expected)
        ->and($notice->link)->toBe($expected);

    $before = AuditLog::query()->where('action', AuditLogger::ACCESS_DENIED)->count();
    $this->actingAs($this->participant)->get($expected)->assertOk();
    expect(AuditLog::query()->where('action', AuditLogger::ACCESS_DENIED)->count())->toBe($before);
});

it('FR-NOTIF-13: من أطفأ جرس المنصّة لهذا النوع تصله الرسالة ولا يُكتب له إشعار', function (): void {
    NotificationPreference::query()->create([
        'user_id' => $this->participant->id, 'type' => 'assignment_published',
        'in_app_enabled' => false, 'email_enabled' => true,
    ]);

    assertAccepted($this->actingAs($this->trainer)
        ->post(route('trainer.assignments.store'), assignmentPayload($this)));

    expect(lettersTo($this->participant, 'emails.assignment_published'))->toBe(1)
        ->and(noticesFor($this->participant, 'assignment_published'))->toBe(0);
});

it('FR-NOTIF-13: المستمع لا يرسل عن مهمة عادت مسودةً قبل أن يعمل الطابور', function (): void {
    $assignment = makeAssignment($this->cohort, ['status' => 'draft']);

    AssignmentPublished::dispatch(
        cohortId: (string) $this->cohort->id,
        assignmentId: (string) $assignment->id,
        assignmentTitle: 'CANARY',
        maxScore: 10,
        dueAt: 'any',
    );

    Mail::assertNotQueued(AtharLetter::class);
});

/*
|--------------------------------------------------------------------------
| FR-ASGN-30 — "remind who has not submitted"
|--------------------------------------------------------------------------
*/

it('FR-ASGN-30: التذكير يصل النشطين الذين لم يسلّموا وحدهم، على القناتين', function (): void {
    $assignment = makeAssignment($this->cohort);
    $submitted = makeParticipant($this->cohort);
    makeSubmission($assignment, $submitted);
    $withdrawn = makeParticipant($this->cohort);
    withdraw($withdrawn, $this);

    $this->actingAs($this->trainer)
        ->post(route('trainer.submissions.remind', $assignment))
        ->assertSessionHas('status', __('trainer.submissions.reminded'));

    expect(lettersTo($this->participant, 'emails.assignment_due_reminder'))->toBe(1)
        ->and(noticesFor($this->participant, 'assignment_due_reminder'))->toBe(1);

    foreach ([$submitted, $withdrawn, $this->trainer] as $other) {
        expect(lettersTo($other, 'emails.assignment_due_reminder'))->toBe(0)
            ->and(noticesFor($other, 'assignment_due_reminder'))->toBe(0);
    }

    expect(AuditLog::query()->where('action', 'assignment.reminded')->sole()->after)
        ->toMatchArray(['recipients' => 1]);
});

it('FR-ASGN-30: الزرّ يظهر حين تُصفّى اللوحة على مهمة منشورة مفتوحة، ولا يظهر بدونها ولا لمسودة', function (): void {
    $open = makeAssignment($this->cohort);
    $draft = makeAssignment($this->cohort, ['status' => 'draft']);

    $action = fn (Assignment $a): string => route('trainer.submissions.remind', ['assignment' => $a->id]);

    $this->actingAs($this->trainer)->get(route('trainer.submissions', ['assignment' => $open->id]))
        ->assertOk()->assertSee($action($open), false);

    $this->actingAs($this->trainer)->get(route('trainer.submissions'))
        ->assertOk()->assertDontSee($action($open), false);

    $this->actingAs($this->trainer)->get(route('trainer.submissions', ['assignment' => $draft->id]))
        ->assertOk()->assertDontSee($action($draft), false);
});

it('FR-ASGN-30: التذكير بمسودة يُرفض 403 ولا يُرسل شيئًا', function (): void {
    $draft = makeAssignment($this->cohort, ['status' => 'draft']);

    $this->actingAs($this->trainer)
        ->post(route('trainer.submissions.remind', $draft))
        ->assertForbidden();

    Mail::assertNotQueued(AtharLetter::class);
    expect(Notification::query()->count())->toBe(0);
});

it('FR-ASGN-30: مدرّب دفعة أخرى يُرفض 403 ولا يُرسل شيئًا', function (): void {
    $assignment = makeAssignment($this->cohort);
    $stranger = makeTrainer(makeCohort());

    $this->actingAs($stranger)
        ->post(route('trainer.submissions.remind', $assignment))
        ->assertForbidden();

    Mail::assertNotQueued(AtharLetter::class);
    expect(Notification::query()->count())->toBe(0);
});

it('FR-ASGN-30: عند الموعد-1ث وعند الموعد يُرسَل، وعند الموعد+1ث يُرفض', function (): void {
    $due = riyadhAt('2026-10-20 23:59:00');

    foreach ([[-1, true], [0, true], [1, false]] as [$offset, $sent]) {
        $assignment = makeAssignment($this->cohort, ['due_at' => $due]);
        freezeAt($due->addSeconds($offset));

        $response = $this->actingAs($this->trainer)->post(route('trainer.submissions.remind', $assignment));

        if ($sent) {
            $response->assertSessionHas('status');
        } else {
            $response->assertSessionHas('error', __('trainer.submissions.remind_closed'));
        }

        expect(Notification::query()->where('link', route('assignments.show', $assignment))->count())
            ->toBe($sent ? 1 : 0, "offset {$offset}s");
    }
});

it('FR-ASGN-30: حين سلّم الجميع لا يُرسل شيء ويُنبَّه المدرّب', function (): void {
    $assignment = makeAssignment($this->cohort);
    makeSubmission($assignment, $this->participant);

    $this->actingAs($this->trainer)
        ->post(route('trainer.submissions.remind', $assignment))
        ->assertSessionHas('warning', __('trainer.submissions.remind_none'))
        ->assertSessionMissing('status');

    Mail::assertNotQueued(AtharLetter::class);
});

it('FR-ASGN-30: ضغطتان متتاليتان، أو مدرّب ومدير معًا، تصلان كل شخص مرة واحدة', function (): void {
    $assignment = makeAssignment($this->cohort);

    $this->actingAs($this->trainer)->post(route('trainer.submissions.remind', $assignment));
    $this->actingAs($this->trainer)->post(route('trainer.submissions.remind', $assignment))
        ->assertSessionHas('warning', __('trainer.submissions.remind_recent'));
    $this->actingAs(makeAdmin())->post(route('trainer.submissions.remind', $assignment))
        ->assertSessionHas('warning', __('trainer.submissions.remind_recent'));

    expect(lettersTo($this->participant, 'emails.assignment_due_reminder'))->toBe(1)
        ->and(noticesFor($this->participant, 'assignment_due_reminder'))->toBe(1);
});

it('FR-ASGN-30: فترة التهدئة — قبل انقضائها بثانية يُرفض، وعند انقضائها يُرسل', function (): void {
    $assignment = makeAssignment($this->cohort, ['due_at' => riyadhAt('2026-10-25 23:59:00')]);
    $start = riyadhAt('2026-10-19 12:00:00');
    $cooldown = (int) config('athar.assignments.reminder_cooldown_minutes');

    $this->actingAs($this->trainer)->post(route('trainer.submissions.remind', $assignment));

    freezeAt($start->addMinutes($cooldown)->subSecond());
    $this->actingAs($this->trainer)->post(route('trainer.submissions.remind', $assignment))
        ->assertSessionHas('warning', __('trainer.submissions.remind_recent'));

    freezeAt($start->addMinutes($cooldown));
    $this->actingAs($this->trainer)->post(route('trainer.submissions.remind', $assignment))
        ->assertSessionHas('status');

    expect(noticesFor($this->participant, 'assignment_due_reminder'))->toBe(2);
});

it('FR-ASGN-30: كل قناة تحترم مفتاحها — البريد مطفأ يُبقي الإشعار، والجرس مطفأ يُبقي الرسالة', function (): void {
    $assignment = makeAssignment($this->cohort);
    $mailOff = makeParticipant($this->cohort);

    NotificationPreference::query()->create([
        'user_id' => $this->participant->id, 'type' => 'assignment_due_reminder',
        'in_app_enabled' => false, 'email_enabled' => true,
    ]);
    NotificationPreference::query()->create([
        'user_id' => $mailOff->id, 'type' => 'assignment_due_reminder',
        'in_app_enabled' => true, 'email_enabled' => false,
    ]);

    $this->actingAs($this->trainer)->post(route('trainer.submissions.remind', $assignment));

    expect(lettersTo($this->participant, 'emails.assignment_due_reminder'))->toBe(1)
        ->and(noticesFor($this->participant, 'assignment_due_reminder'))->toBe(0)
        ->and(lettersTo($mailOff, 'emails.assignment_due_reminder'))->toBe(0)
        ->and(noticesFor($mailOff, 'assignment_due_reminder'))->toBe(1);
});

it('FR-ASGN-30: المدّة المتبقّية بالكلمات لا بصيغة HH:MM:SS', function (): void {
    $assignment = makeAssignment($this->cohort, ['due_at' => riyadhAt('2026-10-27 23:59:00')]);

    $this->actingAs($this->trainer)->post(route('trainer.submissions.remind', $assignment));

    $body = (string) Notification::query()->where('user_id', $this->participant->id)->sole()->body;

    /** @var AtharLetter|null $letter */
    $letter = Mail::queued(AtharLetter::class)->first();

    expect($body)->not->toMatch('/\d{2,}:\d{2}:\d{2}/')
        ->and($body)->toContain((string) trans_choice('app.duration.days', 8, ['count' => 8]))
        ->and((string) ($letter?->values['countdown'] ?? ''))->not->toMatch('/\d{2,}:\d{2}:\d{2}/');
});

it('FR-ASGN-30: الموعد النهائي — في لحظته لم يَفُت، وبعده بثانية فات', function (): void {
    $assignment = makeAssignment($this->cohort, ['due_at' => riyadhAt('2026-10-20 23:59:00')]);

    expect($assignment->isPastDueAt(riyadhAt('2026-10-20 23:58:59')))->toBeFalse()
        ->and($assignment->isPastDueAt(riyadhAt('2026-10-20 23:59:00')))->toBeFalse()
        ->and($assignment->isPastDueAt(riyadhAt('2026-10-20 23:59:01')))->toBeTrue();
});

it('FR-ASGN-30: لوحة التسليمات تُصيَّر لمدرّب بلا دفعة', function (): void {
    $this->actingAs(makeTrainer())
        ->get(route('trainer.submissions'))
        ->assertOk();
});
