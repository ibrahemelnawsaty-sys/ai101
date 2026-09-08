<?php

declare(strict_types=1);

/**
 * BR-33, BR-34, BR-35 — the strongest privilege in the platform.
 *
 * Three things are proved here, in this order of importance:
 *  1. every write is refused while previewing, including writes that no middleware
 *     guards, which is what "enforced at the data-access layer" means;
 *  2. previewing leaves no trace on the previewed account — not a last login, not a
 *     read notification, not a read message, not a download counter;
 *  3. an administrator can never preview another administrator.
 *
 * ASSUMPTIONS declared rather than made silently: the read routes that would normally
 * leave a trace are `notifications`, `messages.poll` and `resources.download`.
 * `messages.poll` is the per-thread read endpoint routes/web.php declares: it asks
 * ThreadPolicy::view() for the thread named in the URL, which is exactly the
 * authorisation the thread screen performs. There is no `messages.show`.
 * PROJECT-CONTRACT.md §10 names `notifications` and `messages.index` but not the
 * message thread view or the resource download.
 *
 * @see BR-33, BR-34, BR-35 · PRD §4.5, §14.1 · CONSTITUTION.md Article 23
 */

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Resource;
use App\Models\Submission;
use App\Models\ThreadParticipant;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
    $this->target = makeParticipant($this->cohort, ['last_login_at' => riyadhAt('2026-10-01 08:00:00')]);
});

/**
 * Start a preview session as the admin and return the response.
 */
function startPreview(object $test, object $admin, object $target)
{
    return $test->actingAs($admin)->post(route('admin.users.preview', $target));
}

/*
|--------------------------------------------------------------------------
| BR-33 — read-only, enforced on the server
|--------------------------------------------------------------------------
*/

it('BR-33: المدير يستطيع معاينة حساب المتدرب ويرى لوحته', function (): void {
    assertAccepted(startPreview($this, $this->admin, $this->target));

    $this->get(route('dashboard'))->assertOk();

    expect(ImpersonationSession::query()
        ->where('admin_id', $this->admin->id)
        ->where('target_id', $this->target->id)
        ->whereNull('ended_at')
        ->count())->toBe(1);
});

it('BR-33: تسجيل الحضور أثناء المعاينة مرفوض على الخادم', function (): void {
    $start = riyadhAt('2026-10-12 18:00:00');
    $session = sessionInCohort($this->cohort, $start, $start->addHours(3));

    startPreview($this, $this->admin, $this->target);
    freezeAt($start);

    $this->post(route('attendance.checkIn', $session))->assertForbidden();

    expect(Attendance::query()->count())->toBe(0);
});

it('BR-33: تسليم مهمة أثناء المعاينة مرفوض على الخادم', function (): void {
    $assignment = makeAssignment($this->cohort, ['due_at' => riyadhAt('2026-10-25 23:59:00')]);

    startPreview($this, $this->admin, $this->target);

    $this->post(route('assignments.submit', $assignment), [
        'note' => 'A submission attempted from inside a preview session.',
    ])->assertForbidden();

    expect(Submission::query()->count())->toBe(0);
});

it('BR-33: إرسال رسالة أثناء المعاينة مرفوض على الخادم', function (): void {
    $thread = makeThreadFor($this->target, $this->cohort);

    startPreview($this, $this->admin, $this->target);

    $this->post(route('messages.store', $thread), [
        'body' => 'A message attempted from inside a preview session.',
    ])->assertForbidden();

    expect(Message::query()->count())->toBe(0);
});

it('BR-33: تعديل الملف الشخصي أثناء المعاينة مرفوض على الخادم', function (): void {
    Profile::factory()->create([
        'user_id' => $this->target->id,
        'phone' => '0500000001',
        'city' => 'CANARY-ORIGINAL-CITY',
    ]);

    startPreview($this, $this->admin, $this->target);

    $this->patch(route('profile.update'), ['city' => 'CANARY-TAMPERED-CITY'])->assertForbidden();

    expect(Profile::query()->where('user_id', $this->target->id)->sole()->city)
        ->toBe('CANARY-ORIGINAL-CITY');
});

it('BR-33: انتهاء المعاينة تلقائيًا بعد ثلاثين دقيقة', function (): void {
    startPreview($this, $this->admin, $this->target);

    freezeAt(riyadhAt('2026-10-12 12:29:59'));
    $this->get(route('dashboard'))->assertOk();
    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(1);

    freezeAt(riyadhAt('2026-10-12 12:30:01'));
    $this->get(route('dashboard'));

    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0);
    $this->assertAuthenticatedAs($this->admin->fresh());
});

it('BR-33: إنهاء المعاينة يعيد المدير إلى جلسته دون تسجيل دخول جديد', function (): void {
    startPreview($this, $this->admin, $this->target);

    assertAccepted($this->delete(route('admin.impersonation.stop')));

    $this->assertAuthenticatedAs($this->admin->fresh());

    expect(ImpersonationSession::query()->whereNotNull('ended_at')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| BR-34 — no trace on the previewed account
|--------------------------------------------------------------------------
*/

it('BR-34: المعاينة لا تغيّر آخر تسجيل دخول للمستخدم', function (): void {
    $before = $this->target->fresh()->last_login_at;

    startPreview($this, $this->admin, $this->target);
    $this->get(route('dashboard'));
    $this->get(route('participant.journey'));

    expect($this->target->fresh()->last_login_at?->equalTo($before))->toBeTrue();
});

it('BR-34: المعاينة لا تعلّم إشعارات المستخدم كمقروءة', function (): void {
    $notification = Notification::factory()->create([
        'user_id' => $this->target->id,
        'is_read' => false,
        'read_at' => null,
    ]);

    startPreview($this, $this->admin, $this->target);
    $this->get(route('notifications'))->assertOk();

    $fresh = $notification->fresh();

    expect($fresh->is_read)->toBeFalse()
        ->and($fresh->read_at)->toBeNull();
});

it('BR-34: المعاينة لا تحدّث حالة قراءة الرسائل', function (): void {
    $thread = makeThreadFor($this->target, $this->cohort);

    Message::factory()->create([
        'thread_id' => $thread->id,
        'sender_id' => makeTrainer($this->cohort)->id,
        'body' => 'A message the trainee has not opened yet.',
        'sent_at' => riyadhAt('2026-10-11 09:00:00'),
    ]);

    startPreview($this, $this->admin, $this->target);
    $this->get(route('messages.poll', $thread))->assertOk();

    expect(ThreadParticipant::query()
        ->where('thread_id', $thread->id)
        ->where('user_id', $this->target->id)
        ->sole()
        ->last_read_at)->toBeNull();
});

it('BR-34: المعاينة لا تزيد عدّاد تحميل الموارد', function (): void {
    $resource = Resource::factory()->create([
        'cohort_id' => $this->cohort->id,
        'type' => 'file',
        'download_count' => 7,
    ]);

    startPreview($this, $this->admin, $this->target);
    $this->get(route('resources.download', $resource));

    expect((int) $resource->fresh()->download_count)->toBe(7);
});

it('BR-34: التصفح العادي خارج المعاينة يترك أثره كالمعتاد', function (): void {
    // The control case. Without it, a broken feature would pass BR-34 trivially.
    //
    // The trace it watches is the resource download counter, the mirror of the
    // preview test three tests above. It used to watch the notification centre
    // instead, on the assumption that opening the page marks its rows read —
    // PRD §9.16 says otherwise: the page filters BY read state and offers an
    // explicit «تعليم الكل كمقروء» button, so opening it marks nothing and the
    // control proved nothing whether the code worked or not.
    // A link resource, so the counter is observed without a file on the disk.
    $resource = Resource::factory()->create([
        'cohort_id' => $this->cohort->id,
        'type' => 'link',
        'external_url' => 'https://example.test/reading-list',
        'download_count' => 7,
    ]);

    $this->actingAs($this->target)->get(route('resources.download', $resource));

    expect((int) $resource->fresh()->download_count)->toBe(8);
});

/*
|--------------------------------------------------------------------------
| BR-35 — audited, and never against another administrator
|--------------------------------------------------------------------------
*/

it('BR-35: لا يمكن معاينة حساب مدير نظام آخر', function (): void {
    $otherAdmin = makeAdmin();

    startPreview($this, $this->admin, $otherAdmin)->assertForbidden();

    expect(ImpersonationSession::query()->count())->toBe(0);
    $this->assertAuthenticatedAs($this->admin->fresh());
});

it('BR-35: المدير لا يعاين نفسه', function (): void {
    startPreview($this, $this->admin, $this->admin)->assertForbidden();

    expect(ImpersonationSession::query()->count())->toBe(0);
});

it('BR-35: المدرب لا يملك صلاحية المعاينة إطلاقًا', function (): void {
    $trainer = makeTrainer($this->cohort);

    $this->actingAs($trainer)
        ->post(route('admin.users.preview', $this->target))
        ->assertForbidden();

    expect(ImpersonationSession::query()->count())->toBe(0);
});

it('BR-35: بداية المعاينة ونهايتها تُسجَّلان في سجل التدقيق مع عنوان IP', function (): void {
    startPreview($this, $this->admin, $this->target);
    $this->delete(route('admin.impersonation.stop'));

    $logs = AuditLog::query()
        ->where('actor_id', $this->admin->id)
        ->where('entity_type', 'impersonation_session')
        ->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->every(fn ($log): bool => $log->ip_address !== null))->toBeTrue()
        ->and($logs->pluck('action')->unique())->toHaveCount(2);
});

it('BR-35: مدة جلسة المعاينة المسجَّلة لا تتجاوز ثلاثين دقيقة', function (): void {
    startPreview($this, $this->admin, $this->target);

    freezeAt(riyadhAt('2026-10-12 13:30:00'));
    $this->get(route('dashboard'));

    $session = ImpersonationSession::query()->sole();

    expect($session->ended_at)->not->toBeNull()
        ->and($session->started_at->diffInMinutes($session->ended_at))->toBeLessThanOrEqual(30);
});
