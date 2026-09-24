<?php

declare(strict_types=1);

/**
 * What the independent security review of D-117 found, each pinned by a test
 * before the fix was trusted (CONSTITUTION Art. 27):
 *
 *  · BR-28 — a running preview is re-checked against the person previewing on
 *    every request; losing the role or the account mid-preview ends it.
 *  · BR-33 / Art. 23 — every way a preview ends writes its end: signing out,
 *    the previewed account being deactivated, the ceiling; and the two exits
 *    still work right after the ceiling.
 *  · BR-32 — `athar:demo-accounts --remove` keeps the last active holder of
 *    each administrative role and writes every removal to the trail.
 *  · D-117 — a system administrator who kept an enrolment or a conversation
 *    from an earlier role receives nothing addressed to that cohort.
 *
 * @see BR-28, BR-32, BR-33 · PRD §4.4, §4.5 · CONSTITUTION Art. 8, Art. 22, Art. 23 · D-117
 */

use App\Enums\AttendanceExceptionType;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\Notification;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Services\Attendance\AttendanceExceptionRequester;
use App\Services\Mail\CohortAudience;
use App\Services\Notifications\CohortNotices;
use App\Services\Permissions\RoleResolver;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->cohort = makeCohort(['status' => 'open']);
    $this->sysadmin = makeSystemAdmin();
    $this->participant = makeParticipant($this->cohort);
});

/** Start a preview of $target as the system administrator, asserted to have begun. */
function integrityStartPreview(object $test, User $target): void
{
    $test->actingAs($test->sysadmin)->post(route('admin.users.preview', $target))->assertRedirect();

    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(1);
}

/*
|--------------------------------------------------------------------------
| BR-28 — the previewer is re-checked on every request
|--------------------------------------------------------------------------
*/

it('BR-28: مدير نظام عُطّل حسابه أثناء المعاينة تنتهي معاينته في طلبه التالي ويُسجَّل خروجه', function (): void {
    makeSystemAdmin();
    integrityStartPreview($this, $this->participant);

    // Another system administrator suspends the previewer mid-preview.
    $this->sysadmin->forceFill(['status' => 'suspended'])->save();

    $this->get(route('schedule'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'access.denied')->where('after->reason', 'impersonation.previewer_revoked')->count())->toBe(1);
});

it('BR-28: مدير نظام تغيّر دوره أثناء المعاينة يعود إلى حسابه في طلبه التالي، وتُسجَّل النهاية', function (): void {
    makeSystemAdmin();
    integrityStartPreview($this, $this->participant);

    $this->sysadmin->forceFill(['role' => 'trainer'])->save();

    $this->get(route('schedule'))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->sysadmin->fresh());
    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'impersonation.stop')->count())->toBe(1);
});

it('BR-28: طلب كتابة من معاينة سُحبت صلاحيتها يُرفض 403 وتنتهي المعاينة، والخروج يعمل', function (): void {
    makeSystemAdmin();
    integrityStartPreview($this, $this->participant);

    $this->sysadmin->forceFill(['role' => 'trainer'])->save();

    $this->patch(route('profile.update'), ['city' => 'CANARY-CITY'])->assertForbidden();

    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0);
    $this->assertAuthenticatedAs($this->sysadmin->fresh());

    // A second preview, revoked, then signed out of: the sign-out goes through.
    $this->sysadmin->forceFill(['role' => 'system_admin'])->save();
    integrityStartPreview($this, $this->participant);
    $this->sysadmin->forceFill(['role' => 'trainer'])->save();

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| BR-33 / Art. 23 — every end of a preview is written
|--------------------------------------------------------------------------
*/

it('BR-33: تسجيل الخروج أثناء المعاينة يُغلق سجلها ويكتب نهايتها في سجل التدقيق', function (): void {
    integrityStartPreview($this, $this->participant);

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'impersonation.stop')->where('actor_id', $this->sysadmin->id)->count())->toBe(1);
});

it('BR-33: تعطيل الحساب المُعايَن أثناء المعاينة ينهيها ويعيد مدير النظام إلى حسابه لا إلى تسجيل الدخول', function (): void {
    integrityStartPreview($this, $this->participant);

    $this->participant->forceFill(['status' => 'suspended'])->save();
    // A real request reads the account afresh from its session; the test's
    // guard keeps the instance it signed in, so it is told to forget it.
    $this->app['auth']->guard('web')->forgetUser();

    $this->get(route('schedule'))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('warning', __('admin.impersonation.target_inactive'));

    $this->assertAuthenticatedAs($this->sysadmin->fresh());
    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'impersonation.stop')->count())->toBe(1);
});

it('BR-33: بعد انتهاء مدة المعاينة يعمل زر الإنهاء — لا 403', function (): void {
    integrityStartPreview($this, $this->participant);

    freezeAt(riyadhAt('2026-10-12 12:30:01'));

    $this->delete(route('admin.impersonation.stop'))->assertRedirect(route('admin.users.index'));

    $this->assertAuthenticatedAs($this->sysadmin->fresh());
    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0);
});

it('BR-33: بعد انتهاء مدة المعاينة يعمل تسجيل الخروج — لا 403', function (): void {
    integrityStartPreview($this, $this->participant);

    freezeAt(riyadhAt('2026-10-12 12:30:01'));

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| BR-32 — the demo accounts' removal
|--------------------------------------------------------------------------
*/

it('BR-32: إزالة الحسابات التجريبية تُبقي آخر مشرف عام وآخر مدير نظام، وتسجّل كل حذف', function (): void {
    // The live host after the D-117 deploy steps: admin@ is the one system
    // administrator and supervisor@ the one general supervisor.
    User::query()->whereKey($this->sysadmin->id)->delete();

    $this->artisan('athar:demo-accounts', ['--password' => 'Demo-Passw0rd!'])->assertSuccessful();

    $this->artisan('athar:demo-accounts', ['--remove' => true])
        ->expectsOutputToContain('kept     admin@athar-demo.test')
        ->expectsOutputToContain('kept     supervisor@athar-demo.test')
        ->assertSuccessful();

    $alive = static fn (string $email): bool => User::query()->where('email', $email)->where('status', 'active')->exists();

    expect($alive('admin@athar-demo.test'))->toBeTrue()
        ->and($alive('supervisor@athar-demo.test'))->toBeTrue()
        ->and(User::query()->whereIn('email', ['trainer@athar-demo.test', 'student@athar-demo.test'])->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'user.deleted')->where('after->via', 'console')->count())->toBe(2);
});

it('BR-32: الفحص المقفل يعطي الجواب نفسه — آخر حامل للدور، ومع وجود غيره', function (): void {
    $roles = new RoleResolver;
    $supervisor = makeAdmin();

    expect($roles->isLastActiveHolder($this->sysadmin, lock: true))->toBeTrue()
        ->and($roles->isLastActiveHolder($supervisor, lock: true))->toBeTrue()
        ->and($roles->isLastActiveHolder($this->participant, lock: true))->toBeFalse();

    makeSystemAdmin();

    expect($roles->isLastActiveHolder($this->sysadmin, lock: true))->toBeFalse()
        ->and($roles->isLastActiveHolder($this->sysadmin))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| D-117 — nothing addressed to a cohort reaches a system administrator
|--------------------------------------------------------------------------
*/

it('D-117: مدير نظام بقي له التحاق متدرّب ومحادثة لا تصله رسائل الدفعة ولا إشعاراتها', function (): void {
    $former = makeParticipant($this->cohort);
    $group = makeThreadFor($former, $this->cohort, ['type' => 'group']);
    ThreadParticipant::factory()->create(['thread_id' => $group->id, 'user_id' => $this->participant->id, 'is_muted' => false]);
    $former->forceFill(['role' => 'system_admin'])->save();

    $audience = app(CohortAudience::class);

    expect($audience->participants($this->cohort->id)->pluck('id')->all())->not->toContain($former->id)
        ->and($audience->participants($this->cohort->id)->pluck('id')->all())->toContain($this->participant->id);

    // A post in the cohort group notifies its other members — never them.
    app(CohortNotices::class)->posted($group, $this->participant, 'CANARY-GROUP-MESSAGE');

    expect(Notification::query()->where('user_id', $former->id)->count())->toBe(0);
});

it('D-117: مدير نظام بقي له إسناد مدرّب لا تصله طلبات الأعذار ولا إشعار التسليمات', function (): void {
    $former = makeTrainer($this->cohort);
    $former->forceFill(['role' => 'system_admin'])->save();
    $trainer = makeTrainer($this->cohort);

    expect(app(CohortAudience::class)->trainerIds($this->cohort->id))->toBe([(string) $trainer->id]);

    $start = riyadhAt('2026-10-11 18:00:00');
    $session = sessionInCohort($this->cohort, $start, $start->addHours(2));
    $attendance = makeAttendance($session, $this->participant, 'absent');

    app(AttendanceExceptionRequester::class)
        ->request($this->participant, $attendance, AttendanceExceptionType::Absence, 'A genuine reason of more than ten characters.');

    expect(Notification::query()->where('user_id', $former->id)->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $trainer->id)->count())->toBeGreaterThan(0);
});
