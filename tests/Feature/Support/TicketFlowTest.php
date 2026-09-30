<?php

declare(strict_types=1);

/**
 * Support tickets — the route a ticket takes, as the owner drew it (D-124):
 *
 *  · it reaches the cohort's primary coordinator, or the general supervisor
 *    when the cohort has none (the safety net);
 *  · the coordinator holding it writes to the participant or keeps a note
 *    internal; above the coordinator a line stays with the team (D-126,
 *    open); the general supervisor adds internal notes to any ticket and
 *    acts only once it reaches them; a coordinator it was not given only
 *    reads it;
 *  · it climbs one level at a time and comes back the same way, the
 *    participant told of every move, by role and never by name; the
 *    coordinator alone tells them it is resolved;
 *  · the primary coordinator hands a ticket to another coordinator, and the
 *    participant is not told (a temporary assumption D-124 records);
 *  · a coordinator who can no longer act loses their tickets to the primary
 *    coordinator, or to the general supervisor.
 *
 * @see D-124 · D-125 · D-126 · BR-22, BR-23, BR-33, BR-34 · CONSTITUTION art. 5, art. 8, art. 22
 */

use App\Mail\AtharLetter;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-05 10:00:00'));
    Storage::fake('private');
    Mail::fake();

    $this->cohort = makeCohort(['status' => 'running']);
    $this->coordinator = makeCoordinator($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->supervisor = makeAdmin();
    $this->sysadmin = makeSystemAdmin();
});

/** Open a ticket as a participant, over HTTP, and hand it back. */
function tkOpen(object $test, User $participant, array $fields = []): SupportTicket
{
    // The clock is frozen, so two tickets share their second: the new one is
    // the one that was not there before.
    $before = SupportTicket::query()->pluck('id')->all();

    $test->actingAs($participant)
        ->post(route('support.store'), $fields + [
            'subject' => 'لا أستطيع رفع ملف المهمة',
            'category' => 'platform',
            'body' => 'يظهر خطأ عند اختيار الملف.',
        ])
        ->assertSessionHasNoErrors();

    return SupportTicket::query()->whereNotIn('id', $before)->sole();
}

/** @return list<string> the notification types this account received, oldest first */
function tkNotices(User $user): array
{
    return Notification::query()->where('user_id', $user->id)->orderBy('created_at')->pluck('type')->all();
}

function tkLettered(User $user, string $copyKey): bool
{
    return Mail::queued(AtharLetter::class, fn (AtharLetter $letter): bool => $letter->copyKey === $copyKey && $letter->hasTo($user->email))->isNotEmpty();
}

/** @return list<array{0: string, 1: bool}> each line's type and whether it is internal, in order */
function tkLines(SupportTicket $ticket): array
{
    return $ticket->entries()->get()->map(fn ($entry): array => [$entry->type->value, $entry->is_internal])->all();
}

it('D-124: التذكرة تصل منسّق الدفعة الأساسي برقم TK، ويصل المتدرب تأكيد برقمها بالمنصة والبريد، والمنسّق إشعار بها', function (): void {
    $ticket = tkOpen($this, $this->participant);

    expect($ticket->number)->toMatch('/^TK-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/')
        ->and($ticket->level->value)->toBe('coordinator')
        ->and($ticket->assignee_id)->toBe($this->coordinator->id)
        ->and($ticket->status->value)->toBe('open')
        ->and($ticket->cohort_id)->toBe($this->cohort->id)
        ->and(tkLines($ticket))->toBe([['opened', false]])
        ->and(AuditLog::query()->where('action', 'support_ticket.opened')->where('entity_id', $ticket->id)->exists())->toBeTrue()
        ->and(tkNotices($this->participant))->toBe(['support_ticket'])
        ->and(tkNotices($this->coordinator))->toBe(['support_ticket_team'])
        ->and(tkLettered($this->participant, 'emails.support_ticket_opened'))->toBeTrue()
        ->and(tkLettered($this->coordinator, 'emails.support_ticket_arrived'))->toBeTrue();

    $receipt = Notification::query()->where('user_id', $this->participant->id)->sole();
    expect($receipt->title)->toContain($ticket->number)
        ->and($receipt->body)->toContain(__('enums.support_ticket_level.coordinator'));
});

it('D-124: مع منسّقَين تصل الأساسيَّ المختار، وبلا أساسي تصل المشرف العام مباشرة — شبكة الأمان', function (): void {
    $second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $second->id]);

    $toPrimary = tkOpen($this, $this->participant);
    expect($toPrimary->assignee_id)->toBe($second->id)->and($toPrimary->level->value)->toBe('coordinator');

    // Two coordinators and none chosen: nobody is primary.
    $this->cohort->update(['primary_coordinator_id' => null]);
    $otherAdmin = makeAdmin();

    $toSupervisor = tkOpen($this, $this->participant, ['subject' => 'تذكرة ثانية']);

    expect($toSupervisor->level->value)->toBe('admin')
        ->and($toSupervisor->assignee_id)->toBeNull()
        ->and(tkNotices($this->supervisor))->toBe(['support_ticket_team'])
        ->and(tkNotices($otherAdmin))->toBe(['support_ticket_team'])
        ->and(tkLettered($this->supervisor, 'emails.support_ticket_arrived'))->toBeTrue();

    // The supervisor's page says why it came straight to them.
    $this->actingAs($this->supervisor)
        ->get(route('support.show', $toSupervisor))
        ->assertOk()
        ->assertSee(__('support.staff_entry.opened_safety_net', ['name' => $this->participant->email]));
});

it('D-124: رسالة المنسّق تصل المتدرب داخل المنصة فقط، والإجراء الداخلي لا يراه المتدرب ولا يُشعَر به', function (): void {
    $ticket = tkOpen($this, $this->participant);
    Mail::fake();

    $this->actingAs($this->coordinator)
        ->post(route('support.note', $ticket), ['body' => 'CANARY-VISIBLE', 'internal' => '0'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('support.flash.messaged'));

    $this->actingAs($this->coordinator)
        ->post(route('support.note', $ticket), ['body' => 'CANARY-INTERNAL', 'internal' => '1'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('support.flash.noted'));

    expect(tkLines($ticket))->toBe([['opened', false], ['message', false], ['note', true]])
        ->and($ticket->fresh()->status->value)->toBe('in_progress')
        ->and(tkNotices($this->participant))->toBe(['support_ticket', 'support_ticket'])
        ->and(Mail::queued(AtharLetter::class)->filter(fn (AtharLetter $l): bool => $l->hasTo($this->participant->email))->count())->toBe(0);

    $this->actingAs($this->participant)
        ->get(route('support.show', $ticket))
        ->assertOk()
        ->assertSee('CANARY-VISIBLE')
        ->assertSee(__('support.entry.message'))
        ->assertDontSee('CANARY-INTERNAL');

    $this->actingAs($this->coordinator)
        ->get(route('support.show', $ticket))
        ->assertSee('CANARY-INTERNAL')
        ->assertSee(__('support.show.internal'));
});

it('D-124: المشرف العام يضيف ملاحظة داخلية على أي تذكرة، ولا يكتب للمتدرب قبل أن تصل إليه', function (): void {
    $ticket = tkOpen($this, $this->participant);

    // It asked to be shown to the participant; it is kept internal.
    $this->actingAs($this->supervisor)
        ->post(route('support.note', $ticket), ['body' => 'CANARY-SUPERVISOR', 'internal' => '0'])
        ->assertSessionHasNoErrors();

    expect(tkLines($ticket))->toBe([['opened', false], ['note', true]])
        ->and($ticket->fresh()->status->value)->toBe('open');

    $this->actingAs($this->participant)->get(route('support.show', $ticket))->assertDontSee('CANARY-SUPERVISOR');

    foreach (['resolve', 'escalate'] as $action) {
        $this->actingAs($this->supervisor)->post(route('support.'.$action, $ticket))->assertForbidden();
    }
});

it('D-124, D-126: ما يكتبه المشرف العام ومدير النظام والتذكرة عندهما يبقى داخليًّا ولو طلبا إظهاره — المنسّق وحده يكتب للمتدرب حتى يقرّر المالك', function (): void {
    $ticket = tkOpen($this, $this->participant);
    $this->actingAs($this->coordinator)->post(route('support.escalate', $ticket))->assertSessionHasNoErrors();

    // It is the supervisor's now: the form offers no choice, and says why.
    $page = (string) $this->actingAs($this->supervisor)->get(route('support.show', $ticket))->assertOk()->getContent();

    expect($page)->toContain(e(__('support.actions.internal_level')))
        ->and($page)->not->toContain(e(__('support.actions.internal')).'<')
        ->and($this->supervisor->can('writeToParticipant', $ticket->fresh()))->toBeFalse();

    // Asked to be shown to the participant; the server keeps it with the team.
    $this->actingAs($this->supervisor)
        ->post(route('support.note', $ticket), ['body' => 'CANARY-ADMIN-LEVEL', 'internal' => '0'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('support.flash.noted'));

    $this->actingAs($this->supervisor)->post(route('support.escalate', $ticket))->assertSessionHasNoErrors();

    $this->actingAs($this->sysadmin)
        ->post(route('support.note', $ticket), ['body' => 'CANARY-SYSADMIN-LEVEL', 'internal' => '0'])
        ->assertSessionHasNoErrors();

    expect(tkLines($ticket))->toBe([['opened', false], ['escalated', false], ['note', true], ['escalated', false], ['note', true]])
        ->and($this->sysadmin->can('writeToParticipant', $ticket->fresh()))->toBeFalse()
        ->and(tkNotices($this->participant))->toBe(['support_ticket', 'support_ticket', 'support_ticket']);

    $this->actingAs($this->participant)
        ->get(route('support.show', $ticket))
        ->assertOk()
        ->assertDontSee('CANARY-ADMIN-LEVEL')
        ->assertDontSee('CANARY-SYSADMIN-LEVEL');

    $this->actingAs($this->supervisor)
        ->get(route('support.show', $ticket))
        ->assertSee('CANARY-ADMIN-LEVEL')
        ->assertSee('CANARY-SYSADMIN-LEVEL');
});

it('D-124: منسّق الدفعة الذي لم تصله التذكرة يراها ولا يتصرّف فيها — 403', function (): void {
    $other = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->coordinator->id]);
    $ticket = tkOpen($this, $this->participant);

    $this->actingAs($other)->get(route('support.show', $ticket))->assertOk()->assertSee(__('support.actions.follow_only'));

    foreach (['note', 'resolve', 'escalate', 'assign'] as $action) {
        $this->actingAs($other)->post(route('support.'.$action, $ticket), ['body' => 'x', 'assignee_id' => $this->coordinator->id])->assertForbidden();
    }

    expect(tkLines($ticket))->toBe([['opened', false]]);
});

it('D-124: تصعد درجةً درجة — المنسّق ← المشرف العام ← مدير النظام، والمتدرب يُبلَّغ بكل تحويل ويرى الدور لا الاسم', function (): void {
    $ticket = tkOpen($this, $this->participant);
    Mail::fake();

    // The system administrator reads only what reached them.
    $this->actingAs($this->sysadmin)->get(route('support.show', $ticket))->assertForbidden();

    $this->actingAs($this->coordinator)
        ->post(route('support.escalate', $ticket), ['escalate_note' => 'CANARY-HANDOVER'])
        ->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->level->value)->toBe('admin')
        ->and($ticket->status->value)->toBe('in_progress')
        ->and(tkLines($ticket))->toBe([['opened', false], ['escalated', false], ['note', true]])
        ->and(tkLettered($this->participant, 'emails.support_ticket_moved'))->toBeTrue()
        ->and(tkLettered($this->supervisor, 'emails.support_ticket_arrived'))->toBeTrue();

    // The coordinator who moved it up may no longer act on it.
    $this->actingAs($this->coordinator)->post(route('support.resolve', $ticket))->assertForbidden();

    $this->actingAs($this->supervisor)->post(route('support.escalate', $ticket))->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->level->value)->toBe('system_admin')
        ->and($ticket->reached_system_admin_at?->equalTo(riyadhAt('2026-10-05 10:00:00')))->toBeTrue()
        ->and(tkNotices($this->sysadmin))->toBe(['support_ticket_team']);

    // No level above the top.
    $this->actingAs($this->sysadmin)->post(route('support.escalate', $ticket))->assertForbidden();

    $page = (string) $this->actingAs($this->participant)->get(route('support.show', $ticket))->assertOk()->getContent();

    expect($page)->toContain(e(__('support.entry.escalated', ['to' => __('enums.support_ticket_level.admin')])))
        ->and($page)->toContain(e(__('support.entry.escalated', ['to' => __('enums.support_ticket_level.system_admin')])))
        ->and($page)->not->toContain('CANARY-HANDOVER')
        ->and($page)->not->toContain(e($this->coordinator->email));
});

it('D-124: ترجع خطوة خطوة — مدير النظام ← المشرف العام ← منسّق يختاره المشرف (الأساسي افتراضيًّا)، ويبقى ما وصل مدير النظام عنده للقراءة', function (): void {
    $second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->coordinator->id]);
    $ticket = tkOpen($this, $this->participant);

    $this->actingAs($this->coordinator)->post(route('support.escalate', $ticket));
    $this->actingAs($this->supervisor)->post(route('support.escalate', $ticket));

    // Never straight down to the coordinator: one level at a time.
    $this->actingAs($this->sysadmin)->post(route('support.return', $ticket), ['return_note' => 'تمّ الإصلاح'])->assertSessionHasNoErrors();
    expect($ticket->fresh()->level->value)->toBe('admin');

    // Still readable by the system administrator it reached.
    $this->actingAs($this->sysadmin)->get(route('support.show', $ticket))->assertOk();

    // A coordinator of another cohort is not a choice.
    $elsewhere = makeCoordinator(makeCohort());
    $this->actingAs($this->supervisor)
        ->post(route('support.return', $ticket), ['coordinator_id' => $elsewhere->id])
        ->assertSessionHasErrors(['message' => __('support.errors.not_a_coordinator')]);

    $this->actingAs($this->supervisor)
        ->post(route('support.return', $ticket), ['coordinator_id' => $second->id])
        ->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->level->value)->toBe('coordinator')
        ->and($ticket->assignee_id)->toBe($second->id)
        ->and(collect(tkLines($ticket))->pluck(0)->all())->toBe(['opened', 'escalated', 'escalated', 'returned', 'note', 'returned']);

    // Back up and down again, choosing no one: the primary coordinator.
    $this->actingAs($second)->post(route('support.escalate', $ticket));
    $this->actingAs($this->supervisor)->post(route('support.return', $ticket))->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBe($this->coordinator->id);
});

it('D-124: تذكرة عند المشرف العام في دفعة بلا منسّق لا تُعاد — يُطلب إسناد منسّق أولًا', function (): void {
    $bare = makeCohort(['status' => 'running']);
    $alone = makeParticipant($bare);
    $ticket = tkOpen($this, $alone);

    expect($ticket->level->value)->toBe('admin');

    $this->actingAs($this->supervisor)
        ->post(route('support.return', $ticket))
        ->assertSessionHasErrors(['message' => __('support.errors.no_coordinator')]);

    $this->actingAs($this->supervisor)
        ->get(route('support.show', $ticket))
        ->assertSee(__('support.actions.no_coordinator'));

    expect($ticket->fresh()->level->value)->toBe('admin');
});

it('D-124: المنسّق الأساسي وحده يسلّم تذكرة عنده لمنسّق آخر في الدفعة، ولا يُبلَّغ المتدرب، والمستلم يعالجها', function (): void {
    $second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->coordinator->id]);
    $ticket = tkOpen($this, $this->participant);
    $before = tkNotices($this->participant);

    // Not to themself, and not to someone outside the cohort.
    $this->actingAs($this->coordinator)
        ->post(route('support.assign', $ticket), ['assignee_id' => $this->coordinator->id])
        ->assertSessionHasErrors(['message' => __('support.errors.not_a_coordinator')]);

    $this->actingAs($this->coordinator)
        ->post(route('support.assign', $ticket), ['assignee_id' => $second->id, 'assign_note' => 'CANARY-ASSIGN'])
        ->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->assignee_id)->toBe($second->id)
        ->and(tkLines($ticket))->toBe([['opened', false], ['assigned', true]])
        ->and(tkNotices($this->participant))->toBe($before)
        ->and(tkNotices($second))->toBe(['support_ticket_team']);

    // Not granted: the primary taking it back, or the new holder passing it on.
    $this->actingAs($this->coordinator)->post(route('support.note', $ticket), ['body' => 'x'])->assertForbidden();
    $this->actingAs($second)->post(route('support.assign', $ticket), ['assignee_id' => $this->coordinator->id])->assertForbidden();

    $this->actingAs($second)->post(route('support.resolve', $ticket))->assertSessionHasNoErrors();
    expect($ticket->fresh()->status->value)->toBe('resolved');

    $this->actingAs($this->participant)->get(route('support.show', $ticket))->assertDontSee('CANARY-ASSIGN');
});

it('D-124: «تمت المعالجة» للمنسّق وحده، ويصل المتدرب إشعار وبريد، وردّه خلال اليوم يعيدها إليه قيد المعالجة', function (): void {
    $ticket = tkOpen($this, $this->participant);
    Mail::fake();

    $this->actingAs($this->coordinator)
        ->post(route('support.resolve', $ticket), ['summary' => 'CANARY-SOLUTION'])
        ->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->status->value)->toBe('resolved')
        ->and($ticket->resolved_at?->equalTo(riyadhAt('2026-10-05 10:00:00')))->toBeTrue()
        ->and(tkLettered($this->participant, 'emails.support_ticket_resolved'))->toBeTrue();

    $this->actingAs($this->participant)
        ->get(route('support.show', $ticket))
        ->assertSee('CANARY-SOLUTION')
        ->assertSee(__('support.reply.reopens'));

    freezeAt(riyadhAt('2026-10-06 09:59:59'));

    $this->actingAs($this->participant)
        ->post(route('support.reply', $ticket), ['body' => 'ما زالت المشكلة'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('support.flash.reopened'));

    $ticket->refresh();
    expect($ticket->status->value)->toBe('in_progress')
        ->and($ticket->resolved_at)->toBeNull()
        ->and($ticket->assignee_id)->toBe($this->coordinator->id)
        ->and(collect(tkLines($ticket))->pluck(0)->all())->toBe(['opened', 'resolved', 'reopened', 'reply'])
        ->and(tkLettered($this->coordinator, 'emails.support_ticket_replied'))->toBeTrue();

    // The day's count stopped: nothing closes it at the old deadline.
    freezeAt(riyadhAt('2026-10-06 10:00:00'));
    $this->artisan('athar:support-tickets')->assertSuccessful();

    expect($ticket->fresh()->status->value)->toBe('in_progress');
});

it('BR-07, D-124: الإغلاق الآلي بعد 24 ساعة من «تمت المعالجة» بالثانية — لا قبلها، ولا ردّ عند حدّها ولو لم يمرّ الإغلاق بعد', function (): void {
    $ticket = tkOpen($this, $this->participant);
    $this->actingAs($this->coordinator)->post(route('support.resolve', $ticket));
    Mail::fake();

    // T + 24h - 1s: still open to an answer, and the pass closes nothing.
    freezeAt(riyadhAt('2026-10-06 09:59:59'));
    $this->artisan('athar:support-tickets')->assertSuccessful();
    expect($ticket->fresh()->status->value)->toBe('resolved');

    // T + 24h, before the pass has run: closed in all but the row.
    freezeAt(riyadhAt('2026-10-06 10:00:00'));
    $this->actingAs($this->participant)
        ->post(route('support.reply', $ticket), ['body' => 'متأخر'])
        ->assertForbidden();
    $this->actingAs($this->participant)
        ->get(route('support.show', $ticket))
        ->assertSee(__('enums.support_ticket_status.closed'))
        ->assertDontSee(__('support.reply.title'));

    // The cron runs signed in as nobody: the platform closed it.
    auth()->forgetUser();
    $this->artisan('athar:support-tickets')->assertSuccessful();

    $ticket->refresh();
    expect($ticket->status->value)->toBe('closed')
        ->and($ticket->closed_by)->toBeNull()
        ->and($ticket->closed_at?->equalTo(riyadhAt('2026-10-06 10:00:00')))->toBeTrue()
        ->and(collect(tkLines($ticket))->pluck(0)->last())->toBe('auto_closed')
        ->and(tkLettered($this->participant, 'emails.support_ticket_closed'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'support_ticket.auto_closed')->where('entity_id', $ticket->id)->whereNull('actor_id')->exists())->toBeTrue();

    // T + 24h + 1s: nothing closes twice.
    freezeAt(riyadhAt('2026-10-06 10:00:01'));
    $this->artisan('athar:support-tickets')->assertSuccessful();
    expect($ticket->entries()->count())->toBe(3);
});

it('D-124: المتدرب يغلق تذكرته في أي مرحلة، وبعد الإغلاق لا يكتب فيها أحد — إلا ملاحظة المشرف العام الداخلية', function (): void {
    $ticket = tkOpen($this, $this->participant);

    $this->actingAs($this->participant)->post(route('support.close', $ticket))->assertSessionHasNoErrors();

    $ticket->refresh();
    $closedActivity = $ticket->last_activity_at;
    expect($ticket->status->value)->toBe('closed')
        ->and($ticket->closed_by)->toBe($this->participant->id)
        ->and(tkNotices($this->participant))->toBe(['support_ticket', 'support_ticket']);

    $this->actingAs($this->participant)->post(route('support.reply', $ticket), ['body' => 'x'])->assertForbidden();
    $this->actingAs($this->participant)->post(route('support.close', $ticket))->assertForbidden();
    $this->actingAs($this->coordinator)->post(route('support.note', $ticket), ['body' => 'x'])->assertForbidden();

    // «ملاحظة داخلية على أي تذكرة»: a closed one too — internal whatever
    // the form says, the ticket stays closed, and the participant's list
    // shows no new activity.
    freezeAt(riyadhAt('2026-10-05 11:00:00'));
    $this->actingAs($this->supervisor)
        ->post(route('support.note', $ticket), ['body' => 'CANARY-AFTER-CLOSE', 'internal' => '0'])
        ->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->status->value)->toBe('closed')
        ->and(collect(tkLines($ticket))->last())->toBe(['note', true])
        ->and($ticket->last_activity_at?->equalTo($closedActivity))->toBeTrue();

    $this->actingAs($this->participant)->get(route('support.show', $ticket))->assertDontSee('CANARY-AFTER-CLOSE');
});

it('D-124: منسّق أُزيل من الدفعة تنتقل تذاكره إلى الأساسي فورًا دون إبلاغ المتدرب', function (): void {
    $second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->coordinator->id]);
    $ticket = tkOpen($this, $this->participant);
    $this->actingAs($this->coordinator)->post(route('support.assign', $ticket), ['assignee_id' => $second->id]);
    $before = tkNotices($this->participant);

    $this->actingAs($this->supervisor)
        ->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $second]))
        ->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->assignee_id)->toBe($this->coordinator->id)
        ->and($ticket->level->value)->toBe('coordinator')
        ->and(tkLines($ticket))->toBe([['opened', false], ['assigned', true], ['assigned', true]])
        ->and(tkNotices($this->participant))->toBe($before)
        ->and(AuditLog::query()->where('action', 'support_ticket.rehomed')->where('entity_id', $ticket->id)->exists())->toBeTrue();
});

it('D-124: منسّق أُوقف حسابه ولا أساسي بعده — يصعد المرور المجدول بتذاكره إلى المشرف العام ويُبلَّغ المتدرب', function (): void {
    $ticket = tkOpen($this, $this->participant);

    // Two more coordinators join: the first stays primary, written down.
    foreach ([makeUser('coordinator'), makeUser('coordinator')] as $joining) {
        $this->actingAs($this->supervisor)
            ->post(route('admin.cohorts.coordinators.attach', $this->cohort), ['coordinator_email' => $joining->email])
            ->assertSessionHasNoErrors();
    }

    // Suspended: two coordinators remain and neither was chosen.
    $this->coordinator->forceFill(['status' => 'suspended'])->save();
    Mail::fake();

    $this->artisan('athar:support-tickets')->assertSuccessful();

    $ticket->refresh();
    expect($ticket->level->value)->toBe('admin')
        ->and(collect(tkLines($ticket))->last())->toBe(['escalated', false])
        ->and(tkLettered($this->participant, 'emails.support_ticket_moved'))->toBeTrue();

    $this->actingAs($this->supervisor)
        ->get(route('support.show', $ticket))
        ->assertSee(e(__('support.staff_entry.escalated_system', ['to' => __('enums.support_ticket_level.admin')])), false);
});

it('D-124: المتدرب يرى تذاكره وحده، والمنسّق تذاكر دفعته، والمشرف العام كلها، ومدير النظام ما وصله — وتغيير المعرّف 403 مسجَّل', function (): void {
    $ticket = tkOpen($this, $this->participant);
    $neighbour = makeParticipant($this->cohort);
    $foreignCoordinator = makeCoordinator(makeCohort());
    $trainer = makeTrainer($this->cohort);

    $denied = AuditLog::query()->where('action', 'access.denied')->count();

    foreach ([$neighbour, $foreignCoordinator, $this->sysadmin, $trainer] as $outsider) {
        $this->actingAs($outsider)->get(route('support.show', $ticket))->assertForbidden();
    }

    expect(AuditLog::query()->where('action', 'access.denied')->count())->toBeGreaterThanOrEqual($denied + 4)
        ->and(AuditLog::query()->where('action', 'access.denied')->latest('created_at')->value('ip_address'))->not->toBeNull();

    $this->actingAs($neighbour)->get(route('support.index'))->assertOk()->assertDontSee($ticket->number);
    $this->actingAs($foreignCoordinator)->get(route('support.index', ['tab' => 'all']))->assertOk()->assertDontSee($ticket->number);
    $this->actingAs($this->sysadmin)->get(route('support.index', ['tab' => 'all']))->assertOk()->assertDontSee($ticket->number);

    $this->actingAs($this->participant)->get(route('support.index'))->assertOk()->assertSee($ticket->number);
    $this->actingAs($this->coordinator)->get(route('support.index'))->assertOk()->assertSee($ticket->number);
    $this->actingAs($this->supervisor)->get(route('support.index', ['tab' => 'all']))->assertOk()->assertSee($ticket->number);
});

it('BR-33, BR-34, D-125: المعاينة لا تقرأ تذاكر الدعم ولا تكتب فيها — 403، ولا رابط إليها، حتى يقرّر المالك', function (): void {
    $ticket = tkOpen($this, $this->participant);
    $lines = tkLines($ticket);

    $this->actingAs($this->sysadmin)->post(route('admin.users.preview', $this->participant))->assertRedirect();

    // D-125 (open): a preview would read more than the system administrator
    // may; until the owner decides, it reads nothing here.
    $this->get(route('support.index'))->assertForbidden();
    $this->get(route('support.show', $ticket))->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('support.index'), false);

    $this->get(route('support.create'))->assertForbidden();
    $this->post(route('support.store'), ['subject' => 'x', 'category' => 'other', 'body' => 'x'])->assertForbidden();
    $this->post(route('support.reply', $ticket), ['body' => 'x'])->assertForbidden();
    $this->post(route('support.close', $ticket))->assertForbidden();

    expect(SupportTicket::query()->count())->toBe(1)
        ->and(tkLines($ticket->fresh()))->toBe($lines)
        ->and($ticket->fresh()->status->value)->toBe('open');
});

it('D-124: المدرّب لا يصل تذاكر الدعم، ولا رابط إليها في شريطه ولا في قائمة حسابه', function (): void {
    $trainer = makeTrainer($this->cohort);
    tkOpen($this, $this->participant);

    $this->actingAs($trainer)->get(route('support.index'))->assertForbidden();

    $this->actingAs($trainer)
        ->get(route('trainer.dashboard', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertDontSee(route('support.index'), false);
});
