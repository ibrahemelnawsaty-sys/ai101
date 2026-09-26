<?php

declare(strict_types=1);

/**
 * The edges of the support-ticket route (D-124), found by the independent
 * logic and security reviews and each pinned here, so a ticket never waits on
 * someone who will never open it:
 *
 *  · the sweep finds the orphans themselves — never a page of healthy tickets
 *    it then skips;
 *  · the person who opened a ticket never holds it, even after becoming a
 *    coordinator of its cohort, and still answers and closes it;
 *  · a trainee with no cohort opens no ticket nobody could resolve;
 *  · a holder who can no longer act hears nothing; the ticket moves first;
 *  · nothing is written after the clock closed a ticket, and the closing is
 *    stamped at the end of its day, whenever the sweep ran;
 *  · a notice that fails changes nothing about a saved change;
 *  · every refusal is in the audit trail, a tampered signed link included;
 *  · a coordinator's text lines are not rationed like uploads.
 *
 * @see D-124 · D-125 · BR-07, BR-22, BR-33 · CONSTITUTION art. 7, art. 8, art. 22
 */

use App\Events\SupportTicketLetter;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use App\Services\Storage\PrivateFileService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-05 10:00:00'));
    Storage::fake('private');
    Mail::fake();

    $this->cohort = makeCohort(['status' => 'running']);
    $this->primary = makeCoordinator($this->cohort);
    $this->second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->primary->id]);
    $this->participant = makeParticipant($this->cohort);
    $this->supervisor = makeAdmin();
});

function tkEdgeOpen(object $test, User $opener): SupportTicket
{
    $before = SupportTicket::query()->pluck('id')->all();

    $test->actingAs($opener)->post(route('support.store'), [
        'subject' => 'لا يعمل رابط الجلسة',
        'category' => 'platform',
        'body' => 'يظهر خطأ عند فتح الرابط.',
    ])->assertSessionHasNoErrors();

    return SupportTicket::query()->whereNotIn('id', $before)->sole();
}

/** @return list<string> */
function tkEdgeNotices(User $user): array
{
    return Notification::query()->where('user_id', $user->id)->orderBy('created_at')->pluck('type')->all();
}

it('D-124: المرور المجدول يجد التذكرة اليتيمة ولو سبقتها مئتا تذكرة سليمة أقدم منها', function (): void {
    SupportTicket::factory()->count(200)->create([
        'opener_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
        'assignee_id' => $this->primary->id,
        'level' => 'coordinator',
        'status' => 'open',
        'last_activity_at' => riyadhAt('2026-10-01 09:00:00'),
    ]);

    $orphan = SupportTicket::factory()->create([
        'opener_id' => $this->participant->id,
        'cohort_id' => $this->cohort->id,
        'assignee_id' => $this->second->id,
        'level' => 'coordinator',
        'status' => 'in_progress',
        'last_activity_at' => riyadhAt('2026-10-04 09:00:00'),
    ]);

    $this->second->forceFill(['status' => 'suspended'])->save();

    $this->artisan('athar:support-tickets')->assertSuccessful();

    expect($orphan->fresh()->assignee_id)->toBe($this->primary->id)
        ->and(SupportTicket::query()->where('assignee_id', $this->primary->id)->count())->toBe(201);
});

it('D-124: صاحب التذكرة لا يستلمها ولو صار منسّقًا في دفعتها — تصعد إلى المشرف العام، ويبقى يرد عليها ويغلقها', function (): void {
    // A coordinator-role account sitting in the cohort as a trainee.
    $trainee = makeUser('coordinator');
    enroll($trainee, $this->cohort, 'participant');
    $ticket = tkEdgeOpen($this, $trainee);

    expect($ticket->assignee_id)->toBe($this->primary->id);

    // Made a coordinator of the same cohort (the one enrolment row is
    // rewritten), then the other two coordinators leave: it is now the
    // cohort's only — so primary — coordinator.
    $this->actingAs($this->supervisor)->post(route('admin.cohorts.coordinators.attach', $this->cohort), ['email' => $trainee->email])->assertSessionHasNoErrors();
    $this->actingAs($this->supervisor)->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $this->second]))->assertSessionHasNoErrors();
    $this->cohort->update(['primary_coordinator_id' => $trainee->id]);
    $this->actingAs($this->supervisor)->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $this->primary]))->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->level->value)->toBe('admin')
        ->and($ticket->assignee_id)->not->toBe($trainee->id);

    // Its own ticket is not returned to it: there is nobody else.
    $this->actingAs($this->supervisor)
        ->post(route('support.return', $ticket), ['coordinator_id' => $trainee->id])
        ->assertSessionHasErrors(['message' => __('support.errors.no_coordinator')]);

    // Still its own to answer and to close, whatever its role is now.
    $this->actingAs($trainee)->post(route('support.reply', $ticket), ['body' => 'ما زالت قائمة'])->assertSessionHasNoErrors();
    $this->actingAs($trainee)->post(route('support.close', $ticket))->assertSessionHasNoErrors();

    expect($ticket->fresh()->status->value)->toBe('closed')
        ->and(tkEdgeNotices($trainee))->not->toContain('support_ticket_team');
});

it('D-124: متدرب بلا دفعة لا يفتح تذكرة لن يستطيع أحد إنهاءها — 403، والصفحة تقول لماذا وأين يكتب', function (): void {
    $withdrawn = makeParticipant($this->cohort);
    Enrollment::query()->where('user_id', $withdrawn->id)->update(['status' => 'withdrawn']);

    $this->actingAs($withdrawn)->get(route('support.create'))->assertForbidden();
    $this->actingAs($withdrawn)
        ->post(route('support.store'), ['subject' => 'x', 'category' => 'other', 'body' => 'x'])
        ->assertForbidden();

    $this->actingAs($withdrawn)
        ->get(route('support.index'))
        ->assertOk()
        ->assertSee(__('support.index.no_cohort', ['email' => config('athar.email')]))
        ->assertDontSee(route('support.create'), false);

    expect(SupportTicket::query()->count())->toBe(0);
});

it('D-124: منسّق أُوقف لا يصله إشعار ردّ المتدرب — تنتقل التذكرة فورًا ويُبلَّغ من استلمها', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);
    $this->actingAs($this->primary)->post(route('support.assign', $ticket), ['assignee_id' => $this->second->id])->assertSessionHasNoErrors();
    $this->second->forceFill(['status' => 'suspended'])->save();
    $heard = count(tkEdgeNotices($this->second));

    $this->actingAs($this->participant)->post(route('support.reply', $ticket), ['body' => 'أي جديد؟'])->assertSessionHasNoErrors();

    expect(count(tkEdgeNotices($this->second)))->toBe($heard)
        ->and($ticket->fresh()->assignee_id)->toBe($this->primary->id)
        ->and(tkEdgeNotices($this->primary))->toContain('support_ticket_team');
});

it('D-124: النقل عند إزالة المنسّق فعلُ المنصة لا المشرف — يقول الخط الزمني «تلقائيًّا»', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);
    $this->actingAs($this->primary)->post(route('support.assign', $ticket), ['assignee_id' => $this->second->id]);

    $this->actingAs($this->supervisor)->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $this->second]))->assertSessionHasNoErrors();

    $moved = $ticket->entries()->get()->last();
    expect($moved->type->value)->toBe('assigned')
        ->and($moved->actor_id)->toBeNull();

    $this->actingAs($this->supervisor)
        ->get(route('support.show', $ticket))
        ->assertSee(__('support.staff_entry.assigned_system', ['target' => $this->primary->email]));
});

it('D-124: تذكرة أغلقتها الساعة لا يُكتب فيها شيء عند إزالة منسّقها قبل مرور الإغلاق', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);
    $this->actingAs($this->primary)->post(route('support.assign', $ticket), ['assignee_id' => $this->second->id]);
    $this->actingAs($this->second)->post(route('support.resolve', $ticket))->assertSessionHasNoErrors();
    $lines = $ticket->entries()->count();
    $heard = count(tkEdgeNotices($this->primary));

    freezeAt(riyadhAt('2026-10-06 10:00:30'));
    $this->actingAs($this->supervisor)->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $this->second]))->assertSessionHasNoErrors();

    expect($ticket->entries()->count())->toBe($lines)
        ->and(count(tkEdgeNotices($this->primary)))->toBe($heard);
});

it('BR-07, D-124: الإغلاق الآلي يُكتب في لحظة انتهاء اليوم ولو تأخر المرور سبع عشرة دقيقة', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);
    $this->actingAs($this->primary)->post(route('support.resolve', $ticket));
    auth()->forgetUser();

    freezeAt(riyadhAt('2026-10-06 10:17:00'));
    $this->artisan('athar:support-tickets')->assertSuccessful();

    $ticket->refresh();
    $closing = $ticket->entries()->get()->last();

    expect($ticket->closed_at?->equalTo(riyadhAt('2026-10-06 10:00:00')))->toBeTrue()
        ->and($closing->type->value)->toBe('auto_closed')
        ->and($closing->created_at?->equalTo(riyadhAt('2026-10-06 10:00:00')))->toBeTrue();
});

it('D-124: منسّق التحاقه «مكتمل» يبقى يملك تذكرته كما تقول الصلاحيات، فلا ينقلها المرور', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);
    Enrollment::query()->where('user_id', $this->primary->id)->where('cohort_id', $this->cohort->id)->update(['status' => 'completed']);

    $this->artisan('athar:support-tickets')->assertSuccessful();

    expect($ticket->fresh()->assignee_id)->toBe($this->primary->id);

    $this->actingAs($this->primary)->post(route('support.resolve', $ticket))->assertSessionHasNoErrors();
});

it('D-124: تعذّر الإشعار بعد الحفظ لا يُفسد التغيير ولا يوقف المرور المجدول', function (): void {
    $first = tkEdgeOpen($this, $this->participant);
    $second = tkEdgeOpen($this, $this->participant);

    Event::listen(SupportTicketLetter::class, static function (): void {
        throw new RuntimeException('mail relay down');
    });

    foreach ([$first, $second] as $ticket) {
        $this->actingAs($this->primary)
            ->post(route('support.resolve', $ticket))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('support.show', $ticket));
    }

    auth()->forgetUser();
    freezeAt(riyadhAt('2026-10-06 10:00:00'));
    $this->artisan('athar:support-tickets')->assertSuccessful();

    expect($first->fresh()->status->value)->toBe('closed')
        ->and($second->fresh()->status->value)->toBe('closed');
});

it('D-124: كل رفض في سير التذكرة يُسجَّل في التدقيق — معرّف منسّق مُعبث به، وتذكرة أُغلقت', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);
    $foreign = makeCoordinator(makeCohort());

    $this->actingAs($this->primary)
        ->post(route('support.assign', $ticket), ['assignee_id' => $foreign->id])
        ->assertSessionHasErrors(['message' => __('support.errors.not_a_coordinator')]);

    $refused = AuditLog::query()->where('action', 'support_ticket.assigned.rejected')->sole();
    expect($refused->entity_id)->toBe($ticket->id)
        ->and($refused->actor_id)->toBe($this->primary->id)
        ->and($refused->after['reason_key'])->toBe('support.errors.not_a_coordinator')
        ->and($refused->ip_address)->not->toBeNull();

    // Returned to a coordinator of another cohort: refused, and written down.
    $this->actingAs($this->primary)->post(route('support.escalate', $ticket))->assertSessionHasNoErrors();
    $this->actingAs($this->supervisor)
        ->post(route('support.return', $ticket), ['coordinator_id' => $foreign->id])
        ->assertSessionHasErrors(['message' => __('support.errors.not_a_coordinator')]);

    expect(AuditLog::query()->where('action', 'support_ticket.returned.rejected')->where('entity_id', $ticket->id)->where('actor_id', $this->supervisor->id)->exists())->toBeTrue()
        ->and($ticket->fresh()->level->value)->toBe('admin');
});

it('D-124: الإعادة بلا اختيار ولا أساسي تطلب اختيار منسّق بعبارتها — لا «المنسّق الذي اخترته»', function (): void {
    $this->cohort->update(['primary_coordinator_id' => null]);
    $ticket = tkEdgeOpen($this, $this->participant);

    expect($ticket->level->value)->toBe('admin');

    $this->actingAs($this->supervisor)
        ->post(route('support.return', $ticket))
        ->assertSessionHasErrors(['message' => __('support.errors.coordinator')]);
});

it('D-124: التسليم بين المنسّقَين لا يغيّر ما يراه المتدرب، والملاحظة الداخلية لا تحرّك «آخر تحديث» عنده', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);
    $opened = $ticket->last_activity_at;

    freezeAt(riyadhAt('2026-10-05 11:00:00'));

    // The handover is internal: neither the stage nor the "last update".
    $this->actingAs($this->primary)->post(route('support.assign', $ticket), ['assignee_id' => $this->second->id])->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->status->value)->toBe('open')
        ->and($ticket->last_activity_at?->equalTo($opened))->toBeTrue();

    // The holder's internal note is handling it — «قيد المعالجة» — but its
    // instant stays internal.
    $this->actingAs($this->second)->post(route('support.note', $ticket), ['body' => 'نتحقق داخليًّا', 'internal' => '1'])->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->status->value)->toBe('in_progress')
        ->and($ticket->last_activity_at?->equalTo($opened))->toBeTrue();

    $this->actingAs($this->second)->post(route('support.note', $ticket), ['body' => 'أرسل لقطة الشاشة', 'internal' => '0'])->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->status->value)->toBe('in_progress')
        ->and($ticket->last_activity_at?->equalTo(riyadhAt('2026-10-05 11:00:00')))->toBeTrue();
});

it('D-124: سطور المنسّق النصية لا تُحسب على حدّ الرفع — خمس وعشرون ملاحظة متتالية تمرّ', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);

    for ($i = 1; $i <= 25; $i++) {
        $this->actingAs($this->primary)
            ->post(route('support.note', $ticket), ['body' => 'ملاحظة '.$i, 'internal' => '1'])
            ->assertSessionHasNoErrors();
    }

    expect($ticket->entries()->where('type', 'note')->count())->toBe(25);
});

it('D-124: الملف يُخدم خاصًّا لا يُخزَّن، ورابطه الموقّع إن عُبث به يُرفض ويُسجَّل الرفض', function (): void {
    $this->actingAs($this->participant)->post(route('support.store'), [
        'subject' => 'لقطة', 'category' => 'platform', 'body' => 'مرفقة.',
        'attachments' => [fakeUpload('shot.png', 'png'), fakeUpload('two.png', 'png')],
    ])->assertSessionHasNoErrors();

    [$mine, $other] = SupportTicketAttachment::query()->orderBy('original_name')->get()->all();
    $signed = app(PrivateFileService::class)->temporaryUrl('files.supportAttachment', ['attachment' => $mine->id]);

    $served = $this->actingAs($this->participant)->get($signed)->assertOk();
    $cache = (string) $served->headers->get('Cache-Control');
    expect($cache)->toContain('private')->toContain('no-store')->not->toContain('public');

    // The id swapped under the same signature.
    $tampered = str_replace($mine->id, $other->id, $signed);
    $before = AuditLog::query()->where('action', 'access.denied')->count();

    $this->actingAs($this->participant)->get($tampered)->assertForbidden();

    expect(AuditLog::query()->where('action', 'access.denied')->count())->toBe($before + 1);
});

it('BR-33, D-125: معاينة حساب المشرف العام لا تقرأ تذاكر الدعم ولا تعرض تبويبها', function (): void {
    $ticket = tkEdgeOpen($this, $this->participant);
    $sysadmin = makeSystemAdmin();

    $this->actingAs($sysadmin)->post(route('admin.users.preview', $this->supervisor))->assertRedirect();

    $this->get(route('support.index'))->assertForbidden();
    $this->get(route('support.show', $ticket))->assertForbidden();

    $this->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('support.index'), false);
});
