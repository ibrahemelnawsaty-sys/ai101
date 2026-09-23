<?php

declare(strict_types=1);

/**
 * The administrator writes to a cohort, and sends the reminders by hand.
 *
 * WHY THIS SUITE EXISTS
 * There was no way to tell a cohort anything by e-mail, and the session and
 * assignment reminders were automatic only (D-87). The cases below hold the
 * parts that are silent when they break: a letter to someone who switched it
 * off, a letter to someone outside the cohort, a double click that writes to
 * everyone twice, markup in a message printed as HTML, and a manual reminder
 * that spends — or is blocked by — an automatic one.
 *
 * @see PRD §9.16, §9.16.1, §9.18 · BR-22, BR-23, BR-33 · D-83, D-87
 */

use App\Enums\BroadcastKind;
use App\Mail\NoticeLetter;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Services\Notifications\ScheduledNotices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
    $this->trainer = makeTrainer($this->cohort);
    $this->a = makeParticipant($this->cohort, ['email' => 'a@example.test']);
    $this->b = makeParticipant($this->cohort, ['email' => 'b@example.test']);
});

function broadcastLetters(?callable $filter = null): int
{
    return Mail::queued(NoticeLetter::class, $filter ?? static fn (): bool => true)->count();
}

function sendMessage(object $test, array $overrides = []): Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->admin)->post(route('admin.broadcasts.store'), array_merge([
        'cohort_id' => $test->cohort->id,
        'subject' => 'CANARY-SUBJECT',
        'body' => "First paragraph.\n\nSecond paragraph.",
        'in_app' => '1',
    ], $overrides));
}

// ------------------------------------------------------------------ the screen

it('D-87: شاشة المراسلة تُصيَّر للمدير وتقول لكل دفعة كم تصل', function (): void {
    $this->actingAs($this->admin)
        ->get(route('admin.broadcasts.index'))
        ->assertOk()
        ->assertViewIs('admin.broadcasts')
        ->assertSee('name="subject"', false)
        ->assertSee('name="body"', false)
        ->assertSee('value="sessions"', false)
        ->assertSee('value="assignments"', false)
        // The option list is handed to the select as data; its label is the
        // count the administrator reads before sending.
        ->assertViewHas('cohortOptions', fn (array $options): bool => $options[0]['label'] === (string) __('admin.broadcasts.cohort_option', [
            'name' => $this->cohort->name,
            'trainees' => trans_choice('admin.broadcasts.trainees', 2, ['count' => 2]),
            'reachable' => trans_choice('admin.broadcasts.trainees', 2, ['count' => 2]),
        ]));
});

it('D-87: للمدير مدخل «مراسلة المتدربين» في شريطه', function (): void {
    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertSee(route('admin.broadcasts.index'), false);
});

// ----------------------------------------------------------------- a message

it('D-87: الرسالة تصل كل متدرّب نشط في الدفعة — بالبريد وعلى المنصة — ولا أحد سواهم', function (): void {
    $withdrawn = makeParticipant($this->cohort, ['email' => 'gone@example.test']);
    Enrollment::query()->where('user_id', $withdrawn->id)->update(['status' => 'withdrawn']);
    $outsider = makeParticipant(makeCohort(), ['email' => 'out@example.test']);

    sendMessage($this)->assertRedirect(route('admin.broadcasts.index'))->assertSessionHas('status');

    expect(broadcastLetters())->toBe(2);

    foreach ([$this->a, $this->b] as $trainee) {
        Mail::assertQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($trainee->email)
            && $l->subjectLine === 'CANARY-SUBJECT'
            && $l->paragraphs === ['First paragraph.', 'Second paragraph.']);
    }

    foreach ([$withdrawn, $outsider, $this->trainer, $this->admin] as $nobody) {
        Mail::assertNotQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($nobody->email));
    }

    expect(Notification::query()->where('type', 'admin_broadcast')->count())->toBe(2)
        ->and(Notification::query()->where('type', 'admin_broadcast')->where('user_id', $this->a->id)->value('title'))->toBe('CANARY-SUBJECT');

    $row = Broadcast::query()->sole();
    expect($row->kind)->toBe(BroadcastKind::Message)
        ->and($row->recipients)->toBe(2)
        ->and($row->emails)->toBe(2)
        ->and(AuditLog::query()->where('action', 'broadcast.sent')->count())->toBe(1);
});

it('D-87: من أوقف «رسائل الإدارة» بالبريد لا يُراسَل، ومن أوقفها على المنصة لا تصله فيها', function (): void {
    NotificationPreference::factory()->create(['user_id' => $this->a->id, 'type' => 'admin_broadcast', 'email_enabled' => false, 'in_app_enabled' => true]);
    NotificationPreference::factory()->create(['user_id' => $this->b->id, 'type' => 'admin_broadcast', 'email_enabled' => true, 'in_app_enabled' => false]);

    sendMessage($this)->assertRedirect();

    Mail::assertNotQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->a->email));
    Mail::assertQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->b->email));

    expect(Notification::query()->where('type', 'admin_broadcast')->where('user_id', $this->a->id)->count())->toBe(1)
        ->and(Notification::query()->where('type', 'admin_broadcast')->where('user_id', $this->b->id)->count())->toBe(0);
});

it('D-87: العدد المسجَّل هو من وصلتهم فعلًا — لا من حاولنا', function (): void {
    // A switched off by e-mail; the message goes without the platform notice.
    NotificationPreference::factory()->create(['user_id' => $this->a->id, 'type' => 'admin_broadcast', 'email_enabled' => false, 'in_app_enabled' => true]);

    sendMessage($this, ['in_app' => '0'])->assertRedirect();

    expect(Broadcast::query()->value('recipients'))->toBe(1)
        ->and(Broadcast::query()->value('emails'))->toBe(1);
});

it('D-87: الأعداد في العربية تتبع المفرد والمثنّى والجمع', function (): void {
    app()->setLocale('ar');

    expect(trans_choice('admin.broadcasts.trainees', 1, ['count' => 1]))->toBe('متدرب واحد')
        ->and(trans_choice('admin.broadcasts.trainees', 2, ['count' => 2]))->toBe('متدربان')
        ->and(trans_choice('admin.broadcasts.trainees', 5, ['count' => 5]))->toBe('5 متدربين')
        ->and(trans_choice('admin.broadcasts.trainees', 12, ['count' => 12]))->toBe('12 متدربًا')
        ->and(trans_choice('admin.broadcasts.minutes', 10, ['count' => 10]))->toBe('10 دقائق');
});

it('D-87: بلا خيار المنصة تصل بالبريد وحده', function (): void {
    sendMessage($this, ['in_app' => '0'])->assertRedirect();

    expect(broadcastLetters())->toBe(2)
        ->and(Notification::query()->where('type', 'admin_broadcast')->count())->toBe(0)
        ->and(Broadcast::query()->value('in_app'))->toBeFalse();
});

it('D-87: الضغط المزدوج لا يرسل الرسالة نفسها مرّتين — وتصحيحها بعد لحظة يُرسَل', function (): void {
    sendMessage($this)->assertRedirect();
    sendMessage($this)->assertSessionHasErrorsIn('broadcast', 'broadcast');

    expect(broadcastLetters())->toBe(2)
        ->and(Broadcast::query()->count())->toBe(1);

    sendMessage($this, ['body' => 'Corrected text.'])->assertRedirect()->assertSessionHasNoErrors();

    expect(Broadcast::query()->count())->toBe(2);

    // After the window the same text may go again.
    freezeAt(riyadhAt('2026-10-12 12:00:00')->addMinutes((int) config('athar.broadcasts.cooldown_minutes') + 1));
    sendMessage($this)->assertSessionHasNoErrors();

    expect(Broadcast::query()->count())->toBe(3);
});

it('D-87: نصّ الرسالة لا يُطبع HTML أبدًا — يُهرَّب، وأسطره تُحفظ', function (): void {
    sendMessage($this, ['body' => "<script>alert(1)</script> <b>bold</b>\nline two"])->assertRedirect();

    $letter = Mail::queued(NoticeLetter::class)->first();
    $html = $letter->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<b>bold</b>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('<br>')
        ->and($html)->toContain('CANARY-SUBJECT');
});

it('D-87: الحقول مطلوبة، والنصّ له حدّ', function (): void {
    sendMessage($this, ['subject' => '', 'body' => ''])->assertSessionHasErrorsIn('broadcast', ['subject', 'body']);
    sendMessage($this, ['body' => str_repeat('a', (int) config('athar.broadcasts.body_max') + 1)])->assertSessionHasErrorsIn('broadcast', 'body');
    sendMessage($this, ['cohort_id' => 'no-such-cohort'])->assertSessionHasErrorsIn('broadcast', 'cohort_id');

    expect(broadcastLetters())->toBe(0)->and(Broadcast::query()->count())->toBe(0);
});

it('D-87: لكل نموذج أخطاؤه — دفعة ناقصة في التذكير لا تظهر تحت نموذج الرسالة', function (): void {
    $page = $this->actingAs($this->admin)
        ->from(route('admin.broadcasts.index'))
        ->followingRedirects()
        ->post(route('admin.broadcasts.remind'), ['kind' => 'sessions']);

    $html = $page->getContent();
    $message = (string) __('validation.required', ['attribute' => __('admin.broadcasts.fields.cohort')]);

    // Once, under the reminder form's own select — and two distinct ids.
    expect(substr_count($html, e($message)))->toBe(1)
        ->and($html)->toContain('id="broadcast-cohort"')
        ->and($html)->toContain('id="reminder-cohort"');
});

// ------------------------------------------------------------- authorisation

it('BR-28: المدرّب والمتدرّب لا يرسلان إلى الدفعة — والمعاينة لا تكتب', function (): void {
    foreach ([$this->trainer, $this->a] as $user) {
        $this->actingAs($user)->get(route('admin.broadcasts.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.broadcasts.store'), [
            'cohort_id' => $this->cohort->id, 'subject' => 'x x x', 'body' => 'y y y',
        ])->assertForbidden();
        $this->actingAs($user)->post(route('admin.broadcasts.remind'), [
            'cohort_id' => $this->cohort->id, 'kind' => 'sessions',
        ])->assertForbidden();
    }

    expect(broadcastLetters())->toBe(0)->and(Broadcast::query()->count())->toBe(0);
});

// --------------------------------------------------- manual session reminder

it('D-87: تذكير الجلسات اليدوي يرسل كل الجلسات القادمة في رسالة واحدة — لا الملغاة ولا الماضية', function (): void {
    sessionInCohort($this->cohort, riyadhAt('2026-10-10 17:00:00'), riyadhAt('2026-10-10 19:00:00'), ['title' => 'CANARY-PAST']);
    sessionInCohort($this->cohort, riyadhAt('2026-10-13 17:00:00'), riyadhAt('2026-10-13 19:00:00'), ['title' => 'CANARY-NEXT', 'topic' => null]);
    sessionInCohort($this->cohort, riyadhAt('2026-10-15 17:00:00'), riyadhAt('2026-10-15 19:00:00'), ['title' => 'CANARY-LATER', 'topic' => null]);
    sessionInCohort($this->cohort, riyadhAt('2026-10-14 17:00:00'), riyadhAt('2026-10-14 19:00:00'), ['title' => 'CANARY-CANCELLED', 'topic' => null, 'status' => 'cancelled']);

    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'sessions'])
        ->assertRedirect(route('admin.broadcasts.index'));

    // One letter per trainee, listing both upcoming sessions in order.
    expect(broadcastLetters())->toBe(2);
    Mail::assertQueued(NoticeLetter::class, function (NoticeLetter $l): bool {
        $titles = array_column($l->rows, 0);

        return $l->hasTo($this->a->email) && $titles === ['CANARY-NEXT', 'CANARY-LATER'];
    });

    expect(Notification::query()->where('type', 'session_reminder')->count())->toBe(2)
        ->and(Broadcast::query()->value('kind'))->toBe(BroadcastKind::Sessions);
});

it('D-87: التذكير اليدوي لا يمسّ التلقائي — تذكير S-24h يصل بعده كما هو', function (): void {
    $start = riyadhAt('2026-10-13 17:00:00');
    sessionInCohort($this->cohort, $start, $start->addHours(2), ['title' => 'CANARY-SESSION']);

    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'sessions'])
        ->assertRedirect();

    expect(DB::table('scheduled_notices')->count())->toBe(0);

    freezeAt($start->subHours(24));
    app(ScheduledNotices::class)->run($start->subHours(24));

    expect(DB::table('scheduled_notices')->where('kind', 'session_reminder_24h')->count())->toBe(1)
        ->and(Notification::query()->where('type', 'session_reminder')->where('user_id', $this->a->id)->count())->toBe(2);
});

it('D-87: لا جلسات قادمة — يُرفض التذكير بسببه ولا يُكتب شيء', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'sessions'])
        ->assertSessionHasErrorsIn('reminder', 'reminder');

    expect(broadcastLetters())->toBe(0)->and(Broadcast::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('D-87: التذكير نفسه للدفعة نفسها لا يتكرّر داخل المهلة — قبل حدّها بثانية يُرفض وعنده يُقبل', function (): void {
    sessionInCohort($this->cohort, riyadhAt('2026-10-20 17:00:00'), riyadhAt('2026-10-20 19:00:00'));
    $minutes = (int) config('athar.broadcasts.cooldown_minutes');
    $first = riyadhAt('2026-10-12 12:00:00');

    $remind = fn () => $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'sessions']);

    $remind()->assertSessionHasNoErrors();

    // «Less than N minutes ago» is refused; N minutes exactly is not less.
    freezeAt($first->addMinutes($minutes)->subSecond());
    $remind()->assertSessionHasErrorsIn('reminder', 'reminder');

    freezeAt($first->addMinutes($minutes));
    $remind()->assertSessionHasNoErrors();

    // And the window starts again from the second send.
    freezeAt($first->addMinutes($minutes)->addSecond());
    $remind()->assertSessionHasErrorsIn('reminder', 'reminder');

    expect(Broadcast::query()->count())->toBe(2);
});

// ------------------------------------------------ manual assignment reminder

it('D-87: تذكير المهام يصل كل متدرّب بما لم يسلّمه هو — ومن سلّم كل شيء لا يصله شيء', function (): void {
    $one = makeAssignment($this->cohort, ['title' => 'CANARY-ONE', 'due_at' => riyadhAt('2026-10-14 23:59:00')]);
    $two = makeAssignment($this->cohort, ['title' => 'CANARY-TWO', 'due_at' => riyadhAt('2026-10-18 23:59:00')]);
    makeAssignment($this->cohort, ['title' => 'CANARY-CLOSED', 'due_at' => riyadhAt('2026-10-11 23:59:00')]);
    makeAssignment($this->cohort, ['title' => 'CANARY-DRAFT', 'due_at' => riyadhAt('2026-10-20 23:59:00'), 'status' => 'draft']);

    // A has handed in both; B has handed in one.
    makeSubmission($one, $this->a);
    makeSubmission($two, $this->a);
    makeSubmission($one, $this->b);

    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'assignments'])
        ->assertRedirect(route('admin.broadcasts.index'));

    Mail::assertNotQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->a->email));
    Mail::assertQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->b->email)
        && array_column($l->rows, 0) === ['CANARY-TWO']);

    expect(Notification::query()->where('type', 'assignment_due_reminder')->where('user_id', $this->a->id)->count())->toBe(0)
        ->and(Notification::query()->where('type', 'assignment_due_reminder')->where('user_id', $this->b->id)->count())->toBe(1)
        ->and(Broadcast::query()->value('recipients'))->toBe(1);
});

it('D-87: المشروع الختامي المفتوح غير المسلَّم يدخل التذكير — والمقفل لا يدخله', function (): void {
    $project = makeFinalProject($this->cohort, ['title' => 'CANARY-PROJECT', 'is_unlocked' => true, 'unlocked_at' => riyadhAt('2026-10-10 09:00:00'), 'due_at' => riyadhAt('2026-10-25 23:59:00')]);
    makeProjectSubmission($project, $this->a);

    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'assignments'])
        ->assertRedirect();

    Mail::assertNotQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->a->email));
    Mail::assertQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->b->email)
        && array_column($l->rows, 0) === ['CANARY-PROJECT']);

    $project->forceFill(['is_unlocked' => false])->save();
    freezeAt(riyadhAt('2026-10-12 13:00:00'));

    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'assignments'])
        ->assertSessionHasErrorsIn('reminder', 'reminder');
});

it('D-87: «مفتوح» كما تعرّفه المنصة — عند الموعد نفسه مفتوح، وبعده بثانية مغلق إلا إن قُبل المتأخر', function (): void {
    $due = riyadhAt('2026-10-12 12:00:00');
    makeAssignment($this->cohort, ['title' => 'CANARY-AT-MARK', 'due_at' => $due]);
    makeAssignment($this->cohort, ['title' => 'CANARY-LATE-OK', 'due_at' => riyadhAt('2026-10-11 23:59:00'), 'allow_late' => true]);
    makeAssignment($this->cohort, ['title' => 'CANARY-LATE-NO', 'due_at' => riyadhAt('2026-10-11 23:59:00'), 'allow_late' => false]);

    // At the deadline itself: still open (the submit endpoint agrees).
    freezeAt($due);
    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'assignments'])
        ->assertRedirect();

    Mail::assertQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->a->email)
        && array_column($l->rows, 0) === ['CANARY-LATE-OK', 'CANARY-AT-MARK']
        && $l->rows[0][1] === (string) __('emails.assignments_digest.late', ['date' => App\Support\Dates::dateTime(riyadhAt('2026-10-11 23:59:00'))]));

    // One second later, past the cooldown: the one without late hand-in is gone.
    Mail::fake();
    freezeAt($due->addMinutes((int) config('athar.broadcasts.cooldown_minutes'))->addSecond());
    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'assignments'])
        ->assertRedirect();

    Mail::assertQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->a->email)
        && array_column($l->rows, 0) === ['CANARY-LATE-OK']);
});

it('D-87: مشروع ختامي مفتوح بلا موعد يدخل التذكير بعبارة «بلا موعد»', function (): void {
    makeFinalProject($this->cohort, ['title' => 'CANARY-OPEN-ENDED', 'is_unlocked' => true, 'unlocked_at' => riyadhAt('2026-10-10 09:00:00'), 'due_at' => null]);

    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'assignments'])
        ->assertRedirect();

    Mail::assertQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->a->email)
        && $l->rows === [['CANARY-OPEN-ENDED', (string) __('emails.assignments_digest.no_deadline')]]);
});

it('D-87: من أوقف تذكير المهام بالبريد لا يُراسَل به يدويًا أيضًا', function (): void {
    makeAssignment($this->cohort, ['title' => 'CANARY-OPEN', 'due_at' => riyadhAt('2026-10-14 23:59:00')]);
    NotificationPreference::factory()->create(['user_id' => $this->a->id, 'type' => 'assignment_due_reminder', 'email_enabled' => false, 'in_app_enabled' => true]);

    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'assignments'])
        ->assertRedirect();

    Mail::assertNotQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->a->email));
    Mail::assertQueued(NoticeLetter::class, fn (NoticeLetter $l): bool => $l->hasTo($this->b->email));
});

it('D-87: الرسالة الرقمية للتذكير تُصيَّر فعلًا — بلا مفتاح نصّ خام', function (): void {
    sessionInCohort($this->cohort, riyadhAt('2026-10-20 17:00:00'), riyadhAt('2026-10-20 19:00:00'), ['title' => 'CANARY-RENDER', 'topic' => null]);

    $this->actingAs($this->admin)
        ->post(route('admin.broadcasts.remind'), ['cohort_id' => $this->cohort->id, 'kind' => 'sessions']);

    $html = Mail::queued(NoticeLetter::class)->first()->render();

    expect($html)->toContain('CANARY-RENDER')
        ->and($html)->toContain((string) __('emails.sessions_digest.cta'))
        ->and($html)->not->toContain('emails.sessions_digest')
        ->and($html)->not->toContain('notifications.digest');
});

it('D-87: «رسائل إدارة البرنامج» في إعدادات إشعارات المتدرّب، قابلة للإيقاف', function (): void {
    $this->actingAs($this->a)
        ->get(route('profile'))
        ->assertOk()
        ->assertSee((string) __('notifications.types.admin_broadcast.label'), false);
});
