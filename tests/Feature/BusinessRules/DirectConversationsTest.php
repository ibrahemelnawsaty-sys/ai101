<?php

declare(strict_types=1);

/**
 * Conversations people start themselves (D-118), asked of the server:
 *
 *   system administrator → a general supervisor (the shared inbox)
 *   general supervisor   → the inbox · any trainer, coordinator, trainee
 *   coordinator          → a general supervisor · their cohort's trainers and trainees
 *   trainer              → their cohort's coordinators — and replies to anyone
 *   trainee              → their cohort's trainers and coordinators
 *
 * One conversation per pair; the inbox read by every system administrator;
 * each person reads their own conversations only (BR-22) — the general
 * supervisor and a cohort's trainer no longer read anyone's by its id; a
 * preview reads the previewed account's and writes nothing (BR-33, BR-34).
 *
 * @see BR-22, BR-33, BR-34 · PRD §9.13 · D-117, D-118, D-119
 */

use App\Models\AuditLog;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Services\Messages\ConversationRules;
use App\Services\Messages\ThreadProvisioner;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->cohort = makeCohort(['status' => 'running']);
    $this->other = makeCohort(['status' => 'running']);

    $this->participant = makeParticipant($this->cohort);
    $this->trainer = makeTrainer($this->cohort);
    $this->coordinator = makeCoordinator($this->cohort);

    $this->otherParticipant = makeParticipant($this->other);
    $this->otherTrainer = makeTrainer($this->other);
    $this->otherCoordinator = makeCoordinator($this->other);

    $this->supervisor = makeAdmin();
    $this->sysadmin = makeSystemAdmin();
});

/** Start a conversation as $from with $recipient (an account, or 'system_admin'). */
function startConversation(object $test, User $from, User|string $recipient, string $body = 'A first message of some length.'): Illuminate\Testing\TestResponse
{
    return $test->actingAs($from)->post(route('messages.start'), [
        'recipient' => $recipient instanceof User ? $recipient->id : $recipient,
        'body' => $body,
    ]);
}

/** @return list<string> */
function recipientIds(User $from): array
{
    return app(ConversationRules::class)->recipients($from)->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
}

/** @param  list<User>  $users @return list<string> */
function idsOf(array $users): array
{
    return collect($users)->map(fn (User $u) => (string) $u->id)->sort()->values()->all();
}

/** The one conversation the two share, if any. */
function sharedThread(User $one, User $other): ?Thread
{
    return Thread::query()
        ->whereHas('participants', fn ($q) => $q->where('user_id', $one->id))
        ->whereHas('participants', fn ($q) => $q->where('user_id', $other->id))
        ->first();
}

/*
|--------------------------------------------------------------------------
| The rule, role by role
|--------------------------------------------------------------------------
*/

it('D-118: من يُعرض في «محادثة جديدة» هو القاعدة حرفيًا، لكل دور', function (): void {
    expect(recipientIds($this->sysadmin))->toBe(idsOf([$this->supervisor]))
        ->and(recipientIds($this->supervisor))->toBe(idsOf([
            $this->participant, $this->trainer, $this->coordinator,
            $this->otherParticipant, $this->otherTrainer, $this->otherCoordinator,
        ]))
        ->and(recipientIds($this->coordinator))->toBe(idsOf([$this->supervisor, $this->trainer, $this->participant]))
        ->and(recipientIds($this->trainer))->toBe(idsOf([$this->coordinator]))
        ->and(recipientIds($this->participant))->toBe(idsOf([$this->trainer, $this->coordinator]));

    $rules = app(ConversationRules::class);

    expect($rules->mayWriteToInbox($this->supervisor))->toBeTrue()
        ->and($rules->mayWriteToInbox($this->coordinator))->toBeFalse()
        ->and($rules->mayWriteToInbox($this->sysadmin))->toBeFalse();
});

it('D-118: كل زوج مسموح يبدأ محادثة وتصل رسالته الأولى', function (): void {
    $allowed = [
        [$this->participant, $this->trainer],
        [$this->participant, $this->coordinator],
        [$this->trainer, $this->coordinator],
        [$this->coordinator, $this->supervisor],
        [$this->supervisor, $this->otherParticipant],
        [$this->sysadmin, $this->supervisor],
    ];

    foreach ($allowed as [$from, $to]) {
        startConversation($this, $from, $to, 'CANARY-'.$from->role->value.'-TO-'.$to->role->value)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect(Message::query()->where('body', 'CANARY-'.$from->role->value.'-TO-'.$to->role->value)->where('sender_id', $from->id)->exists())->toBeTrue();
    }
});

it('D-118: 403 — كل زوج خارج القاعدة يُرفض، ولا تُنشأ محادثة، ويُسجَّل الرفض', function (): void {
    $refused = [
        [$this->trainer, $this->participant],        // the trainer replies, never starts
        [$this->trainer, $this->supervisor],
        [$this->participant, $this->otherTrainer],   // another cohort
        [$this->participant, $this->supervisor],
        [$this->participant, $this->sysadmin],
        [$this->coordinator, $this->otherTrainer],   // another cohort
        [$this->coordinator, $this->sysadmin],
        [$this->supervisor, $this->sysadmin],        // the inbox, not a person
        [$this->supervisor, makeAdmin()],
        [$this->sysadmin, $this->participant],
    ];

    $threadsBefore = Thread::query()->count();
    $deniedBefore = AuditLog::query()->where('action', 'access.denied')->count();

    foreach ($refused as [$from, $to]) {
        startConversation($this, $from, $to)->assertForbidden();
    }

    expect(Thread::query()->count())->toBe($threadsBefore)
        ->and(Message::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'access.denied')->count() - $deniedBefore)->toBe(count($refused));

    // Only the supervisor writes to the inbox.
    startConversation($this, $this->coordinator, 'system_admin')->assertForbidden();
});

it('D-119: حساب مدعوّ لم يقبل دعوته لا يُعرض ولا تُبدأ معه محادثة', function (): void {
    $invited = makeCoordinator($this->cohort, ['email_verified_at' => null]);

    expect(recipientIds($this->participant))->not->toContain((string) $invited->id);

    startConversation($this, $this->participant, $invited)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Replies, and one conversation per pair
|--------------------------------------------------------------------------
*/

it('D-118: المدرب يرد على متدرب بدأ المحادثة، ولا يبدؤها هو', function (): void {
    startConversation($this, $this->trainer, $this->participant)->assertForbidden();

    startConversation($this, $this->participant, $this->trainer, 'CANARY-QUESTION')->assertRedirect();
    $thread = sharedThread($this->participant, $this->trainer);

    $this->actingAs($this->trainer)
        ->post(route('messages.store', $thread), ['body' => 'CANARY-ANSWER'])
        ->assertSessionHasNoErrors();

    expect(Message::query()->where('thread_id', $thread->id)->pluck('body')->all())
        ->toBe(['CANARY-QUESTION', 'CANARY-ANSWER']);
});

it('D-118: محادثة واحدة لكل شخصين أيًّا كان من بدأ، وخط المدرب القائم يُفتح ولا يُكرَّر', function (): void {
    startConversation($this, $this->participant, $this->coordinator, 'ONE')->assertRedirect();
    startConversation($this, $this->coordinator, $this->participant, 'TWO')->assertRedirect();
    startConversation($this, $this->participant, $this->coordinator, 'THREE')->assertRedirect();

    $between = Thread::query()
        ->where('pair_key', Thread::pairKeyFor($this->participant, $this->coordinator))
        ->get();

    expect($between)->toHaveCount(1)
        ->and(Message::query()->where('thread_id', $between->first()->id)->count())->toBe(3);

    // The provisioned trainer line (D-82) is the pair's conversation.
    app(ThreadProvisioner::class)->provisionCohort($this->cohort);
    $line = Thread::query()->where('type', 'trainer_dm')->sole();

    startConversation($this, $this->participant, $this->trainer, 'ON-THE-LINE')
        ->assertRedirect(route('messages.index', ['thread' => $line->id]));

    expect(Thread::query()->where('type', 'direct')->whereNull('inbox')->count())->toBe(1)
        ->and(Message::query()->where('body', 'ON-THE-LINE')->value('thread_id'))->toBe($line->id);
});

/*
|--------------------------------------------------------------------------
| The system administrators' shared inbox
|--------------------------------------------------------------------------
*/

it('D-118: صندوق مشترك — كل مديري النظام يقرؤونه ويُشعَرون، ومن يُضاف لاحقًا يراه، ومن غادر الدور لا يراه', function (): void {
    $colleague = makeSystemAdmin();

    startConversation($this, $this->supervisor, 'system_admin', 'CANARY-TO-ADMINS')->assertRedirect();
    $inbox = Thread::query()->where('inbox', 'system_admin')->sole();

    // Both administrators are told, on the platform.
    foreach ([$this->sysadmin, $colleague] as $admin) {
        expect(Notification::query()->where('user_id', $admin->id)->where('type', 'message_received')->count())->toBe(1);
        $this->actingAs($admin)->get(route('messages.index'))->assertOk()->assertSee('CANARY-TO-ADMINS');
    }

    // Any of them replies; the supervisor is told.
    $this->actingAs($colleague)
        ->post(route('messages.store', $inbox), ['body' => 'CANARY-REPLY'])
        ->assertSessionHasNoErrors();

    expect(Notification::query()->where('user_id', $this->supervisor->id)->where('type', 'message_received')->count())->toBe(1);

    // A system administrator writing to that supervisor lands in the same thread.
    startConversation($this, $this->sysadmin, $this->supervisor, 'CANARY-SAME-THREAD')
        ->assertRedirect(route('messages.index', ['thread' => $inbox->id]));
    expect(Thread::query()->where('inbox', 'system_admin')->count())->toBe(1);

    // One who arrives later reads the old conversation.
    $newcomer = makeSystemAdmin();
    $this->actingAs($newcomer)->get(route('messages.index'))->assertOk()->assertSee('CANARY-TO-ADMINS');
    expect(ThreadParticipant::query()->where('thread_id', $inbox->id)->where('user_id', $newcomer->id)->exists())->toBeTrue();

    // One who left the role keeps a row and loses the thread.
    $colleague->forceFill(['role' => 'trainer'])->save();
    $this->actingAs($colleague->fresh())->get(route('messages.poll', $inbox))->assertForbidden();
    $this->actingAs($colleague->fresh())->get(route('messages.index'))->assertDontSee('CANARY-TO-ADMINS');
});

it('D-118: المشرف العام يرى محادثة الصندوق باسم «مدير النظام»، ومدير النظام يراها باسم المشرف', function (): void {
    startConversation($this, $this->supervisor, 'system_admin')->assertRedirect();

    $this->actingAs($this->supervisor)->get(route('messages.index'))
        ->assertOk()->assertSee(__('messages.inbox.title'));

    $this->actingAs($this->sysadmin)->get(route('messages.index'))
        // Titled by the supervisor — their name, or their address when the
        // account has no profile yet.
        ->assertOk()->assertSee($this->supervisor->email);
});

/*
|--------------------------------------------------------------------------
| Each person reads their own conversations only (BR-22)
|--------------------------------------------------------------------------
*/

it('BR-22 (D-118): 403 — لا المشرف العام ولا مدرب آخر في الدفعة ولا المنسق ولا مدير النظام يفتح محادثة غيره', function (): void {
    app(ThreadProvisioner::class)->provisionCohort($this->cohort);
    $colleague = makeTrainer($this->cohort);
    $line = Thread::query()->where('type', 'trainer_dm')
        ->whereHas('participants', fn ($q) => $q->where('user_id', $this->trainer->id))
        ->sole();

    startConversation($this, $this->participant, $this->coordinator)->assertRedirect();
    $direct = sharedThread($this->participant, $this->coordinator);

    foreach ([$this->supervisor, $colleague, $this->coordinator, $this->sysadmin] as $outsider) {
        $this->actingAs($outsider)->get(route('messages.poll', $line))->assertForbidden();
        $this->actingAs($outsider)->post(route('messages.store', $line), ['body' => 'CANARY-INTRUDER'])->assertForbidden();
    }

    foreach ([$this->supervisor, $this->trainer, $this->sysadmin] as $outsider) {
        $this->actingAs($outsider)->get(route('messages.poll', $direct))->assertForbidden();
    }

    expect(Message::query()->where('body', 'CANARY-INTRUDER')->exists())->toBeFalse();

    // The two members read it.
    $this->actingAs($this->participant)->get(route('messages.poll', $direct))->assertOk();
    $this->actingAs($this->coordinator)->get(route('messages.poll', $direct))->assertOk();
});

it('BR-33 (D-118): المعاينة تعرض محادثات الحساب المُعايَن، ولا تبدأ محادثة ولا تعلّم شيئًا مقروءًا', function (): void {
    startConversation($this, $this->coordinator, $this->participant, 'CANARY-SEEN-IN-PREVIEW')->assertRedirect();
    $thread = sharedThread($this->participant, $this->coordinator);

    $this->actingAs($this->sysadmin)->post(route('admin.users.preview', $this->participant))->assertRedirect();

    $this->get(route('messages.index', ['thread' => $thread->id]))
        ->assertOk()
        ->assertSee('CANARY-SEEN-IN-PREVIEW')
        ->assertDontSee(route('messages.create'), false);

    $this->get(route('messages.create'))->assertForbidden();
    $this->post(route('messages.start'), ['recipient' => $this->trainer->id, 'body' => 'CANARY-FROM-PREVIEW'])->assertForbidden();

    expect(ThreadParticipant::query()->where('thread_id', $thread->id)->where('user_id', $this->participant->id)->value('last_read_at'))->toBeNull()
        ->and(Message::query()->where('body', 'CANARY-FROM-PREVIEW')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The screens
|--------------------------------------------------------------------------
*/

it('D-118: صفحة «محادثة جديدة» تعرض المسموح وحده، والصندوق للمشرف وحده، وتبحث، ولها حالتها الفارغة', function (): void {
    $this->actingAs($this->participant)->get(route('messages.create'))
        ->assertOk()
        ->assertSee('value="'.$this->trainer->id.'"', false)
        ->assertSee('value="'.$this->coordinator->id.'"', false)
        ->assertDontSee('value="'.$this->otherTrainer->id.'"', false)
        ->assertDontSee('value="system_admin"', false);

    $this->actingAs($this->supervisor)->get(route('messages.create'))
        ->assertOk()
        ->assertSee('value="system_admin"', false)
        ->assertSee(__('messages.inbox.option_hint'));

    $this->actingAs($this->supervisor)->get(route('messages.create', ['q' => $this->otherTrainer->email]))
        ->assertOk()
        ->assertSee('value="'.$this->otherTrainer->id.'"', false)
        ->assertDontSee('value="'.$this->participant->id.'"', false);

    $lonely = makeTrainer(makeCohort());

    $this->actingAs($lonely)->get(route('messages.create'))
        ->assertOk()
        ->assertSee(__('messages.start.empty_title'));
});

it('D-118: رابط الرسائل في قوائم المشرف العام والمنسق، و«التواصل» عند مدير النظام، مع عدّاد غير المقروء', function (): void {
    startConversation($this, $this->participant, $this->coordinator)->assertRedirect();

    $rail = $this->actingAs($this->coordinator)->get(route('coordinator.dashboard', ['cohort' => $this->cohort->id]))->assertOk()->getContent();

    expect($rail)->toContain('href="'.route('messages.index').'"');

    $this->actingAs($this->supervisor)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('href="'.route('messages.index').'"', false);

    $this->actingAs($this->sysadmin)->get(route('admin.users.index'))->assertOk()
        ->assertSee(__('nav.admin.contact'));
});

it('D-118: أول رسالة تُشعر المستلم داخل المنصة، وطلب بلا مستلم أو بلا نص يُشرح', function (): void {
    startConversation($this, $this->participant, $this->coordinator)->assertRedirect();

    expect(Notification::query()->where('user_id', $this->coordinator->id)->where('type', 'message_received')->count())->toBe(1);

    $this->actingAs($this->participant)->post(route('messages.start'), ['body' => 'no recipient'])
        ->assertSessionHasErrors(['recipient' => __('messages.start.recipient_required')]);
    $this->actingAs($this->participant)->post(route('messages.start'), ['recipient' => $this->coordinator->id, 'body' => '  '])
        ->assertSessionHasErrors(['body' => __('messages.start.body_required')]);
});

it('D-118: زر تعديل الرسالة نموذج PATCH يعمل — لا رابط GET إلى مسار لا يقبله', function (): void {
    startConversation($this, $this->participant, $this->coordinator, 'CANARY-TYPO')->assertRedirect();
    $message = Message::query()->where('body', 'CANARY-TYPO')->sole();
    $thread = sharedThread($this->participant, $this->coordinator);

    $page = $this->actingAs($this->participant)->get(route('messages.index', ['thread' => $thread->id]))->assertOk()->getContent();

    expect($page)->toContain('action="'.route('messages.edit', $message->id).'"')
        ->and($page)->toContain('name="_method" value="PATCH"')
        ->and($page)->not->toContain('href="'.route('messages.edit', $message->id).'"');

    $this->actingAs($this->participant)
        ->patch(route('messages.edit', $message->id), ['body' => 'CANARY-FIXED'])
        ->assertSessionHasNoErrors();

    expect($message->fresh()->body)->toBe('CANARY-FIXED');
});

it('D-118: الأسماء من الملف الشخصي — في «محادثة جديدة»، وعنوان المحادثة، وصندوق مدير النظام', function (): void {
    $coordinatorName = App\Models\Profile::factory()->create(['user_id' => $this->coordinator->id])->fresh()->full_name_ar;
    $supervisorName = App\Models\Profile::factory()->create(['user_id' => $this->supervisor->id])->fresh()->full_name_ar;

    expect($coordinatorName)->not->toBe('')->and($supervisorName)->not->toBe('');

    $this->actingAs($this->participant)->get(route('messages.create'))
        ->assertOk()
        ->assertSee($coordinatorName);

    startConversation($this, $this->participant, $this->coordinator)->assertRedirect();

    $this->actingAs($this->participant)->get(route('messages.index'))
        ->assertOk()
        ->assertSee($coordinatorName);

    startConversation($this, $this->supervisor, 'system_admin')->assertRedirect();

    $this->actingAs($this->sysadmin)->get(route('messages.index'))
        ->assertOk()
        ->assertSee($supervisorName);
});
