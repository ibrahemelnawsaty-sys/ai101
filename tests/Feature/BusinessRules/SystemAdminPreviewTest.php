<?php

declare(strict_types=1);

/**
 * What the owner decided about the system administrator's account preview
 * (D-117), each asked of the server, not of a hidden button:
 *
 *  · no file leaves the platform from inside a preview — every export, the
 *    bulk download of submissions and the self-check-in code are the general
 *    supervisor's and the account holder's, never the system administrator's.
 *    The endpoints answer 403 and write the refusal; the screens draw no
 *    export button and say why the code is not shown.
 *  · a preview shows an account as its holder sees it, so an account that
 *    sees nothing — suspended, deleted, not yet activated — is refused, as are
 *    one's own account and another system administrator's. The refusal says
 *    which, on the list and on the account page.
 *
 * @see BR-33, BR-34, BR-35 · PRD §4.5 · CONSTITUTION Art. 5, Art. 23 · D-106, D-117
 */

use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Permissions\ImpersonationService;

beforeEach(function (): void {
    $this->start = riyadhAt('2026-10-12 18:00:00');
    freezeAt($this->start);

    $this->cohort = makeCohort(['status' => 'running']);
    $this->sysadmin = makeSystemAdmin();
    $this->supervisor = makeAdmin();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->session = sessionInCohort($this->cohort, $this->start, $this->start->addHours(2));
});

/** Begin previewing $target as the system administrator; asserted to have begun. */
function previewAs(object $test, User $target): void
{
    $test->actingAs($test->sysadmin)->post(route('admin.users.preview', $target))->assertRedirect();

    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(1);
}

function endPreview(object $test): void
{
    $test->delete(route('admin.impersonation.stop'))->assertRedirect();

    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0);
}

function previewExportDenials(): int
{
    return AuditLog::query()
        ->where('action', 'access.denied')
        ->where('after->reason', 'impersonation.write_attempt')
        ->count();
}

/*
|--------------------------------------------------------------------------
| No file leaves the platform from a preview
|--------------------------------------------------------------------------
*/

it('BR-33: أثناء معاينة متدرب يُرفض تصدير حضوره ودرجاته 403 ويُسجَّل الرفض', function (): void {
    previewAs($this, $this->participant);

    $this->get(route('attendance.export', ['cohort' => $this->cohort->id]))->assertForbidden();
    $this->get(route('grades.export', ['cohort' => $this->cohort->id]))->assertForbidden();

    expect(previewExportDenials())->toBe(2);
});

it('BR-33: أثناء معاينة مدرب يُرفض كل تصدير وتنزيل ورمز التحضير 403، ويُسجَّل كل رفض', function (): void {
    $assignment = makeAssignment($this->cohort);
    previewAs($this, $this->trainer);

    $scoped = ['cohort' => $this->cohort->id];
    $refused = [
        route('trainer.attendance.export', $scoped),
        route('trainer.participants.export', $scoped),
        route('trainer.reports.export', $scoped),
        route('trainer.submissions.export', $scoped),
        route('trainer.submissions.bulkDownload', ['assignment' => $assignment->id] + $scoped),
        route('trainer.attendance.checkinCode', ['session' => $this->session->id] + $scoped),
    ];

    foreach ($refused as $url) {
        $this->get($url)->assertForbidden();
    }

    expect(previewExportDenials())->toBe(count($refused));
});

it('BR-33: أثناء معاينة المشرف العام تُرفض تصديراته كلها 403 — والشاشات نفسها تُفتح', function (): void {
    previewAs($this, $this->supervisor);

    $refused = [
        route('admin.registrations.export'),
        route('admin.certificates.export'),
        route('admin.reports.export'),
        route('admin.audit.export'),
    ];

    foreach ($refused as $url) {
        $this->get($url)->assertForbidden();
    }

    // Looking stays open: the preview is for seeing the account as it is.
    $this->get(route('admin.registrations.index'))->assertOk()
        ->assertDontSee(route('admin.registrations.export'), false);

    expect(previewExportDenials())->toBe(count($refused));
});

it('BR-33: شاشات المعاينة بلا زر تصدير، وبطاقة رمز التحضير تشرح غيابه ولا تطلبه', function (): void {
    previewAs($this, $this->trainer);

    $page = $this->get(route('trainer.attendance', ['cohort' => $this->cohort->id, 'session' => $this->session->id]))
        ->assertOk()
        ->assertSee(__('attendance.checkin_code.preview_title'))
        ->assertDontSee('atharCheckinCode(', false)
        ->assertDontSee(route('trainer.attendance.export'), false)
        ->getContent();

    expect($page)->not->toContain(route('trainer.attendance.checkinCode', $this->session->id));

    endPreview($this);
    previewAs($this, $this->participant);

    $this->get(route('attendance.index', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertDontSee(route('attendance.export'), false);
});

it('D-106: خارج المعاينة يبقى رمز التحضير والتصدير للمدرب كما هما', function (): void {
    $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['cohort' => $this->cohort->id, 'session' => $this->session->id]))
        ->assertOk()
        ->assertSee('atharCheckinCode(', false)
        ->assertSee(route('trainer.attendance.export'), false)
        ->assertDontSee(__('attendance.checkin_code.preview_title'));

    $this->actingAs($this->trainer)
        ->get(route('trainer.attendance.checkinCode', ['session' => $this->session->id, 'cohort' => $this->cohort->id]))
        ->assertOk();

    $this->actingAs($this->participant)
        ->get(route('attendance.index', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(route('attendance.export'), false);
});

/*
|--------------------------------------------------------------------------
| Which accounts may be previewed, and the reason when not
|--------------------------------------------------------------------------
*/

it('BR-35: لا معاينة لحساب معلّق ولا غير مفعّل ولا لنفسك ولا لمدير نظام آخر — 403، والسبب ظاهر في صفحته', function (): void {
    $cases = [
        'admin.preview.inactive_blocked' => makeParticipant($this->cohort, ['status' => 'suspended']),
        'admin.preview.unverified_blocked' => makeParticipant($this->cohort, ['email_verified_at' => null]),
        'admin.preview.self_blocked' => $this->sysadmin,
        'admin.preview.admin_blocked' => makeSystemAdmin(),
    ];

    foreach ($cases as $reason => $target) {
        $this->actingAs($this->sysadmin)
            ->post(route('admin.users.preview', $target))
            ->assertForbidden();

        $this->actingAs($this->sysadmin)
            ->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee(__($reason))
            ->assertSee(__('admin.preview.blocked_body'))
            ->assertDontSee(route('admin.users.preview', $target), false);
    }

    expect(ImpersonationSession::query()->count())->toBe(0);
});

it('BR-35: قائمة المستخدمين تكتب سبب المنع بجانب الحساب، وتعرض زر المعاينة لمن يُعايَن', function (): void {
    $suspended = makeParticipant($this->cohort, ['status' => 'suspended']);

    $this->actingAs($this->sysadmin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee(__('admin.preview.inactive_blocked'))
        ->assertSee(__('admin.preview.self_blocked'))
        ->assertSee(route('admin.users.preview', $this->participant), false)
        ->assertSee(route('admin.users.preview', $this->supervisor), false)
        ->assertDontSee(route('admin.users.preview', $suspended), false);
});

it('BR-35: الحساب المحذوف يُرفض بسببه — لا يُعاين ولا يُفتح له سجل معاينة', function (): void {
    $gone = makeParticipant($this->cohort);
    $gone->forceFill(['status' => 'deleted'])->save();

    expect(app(ImpersonationService::class)->refusalReason($this->sysadmin, $gone->fresh()))->toBe('admin.preview.deleted_blocked');

    $status = $this->actingAs($this->sysadmin)->post(route('admin.users.preview', $gone))->status();

    expect($status)->toBe(403)
        ->and(ImpersonationSession::query()->count())->toBe(0);
});

it('BR-35: المعاينة تبقى متاحة لحساب فعّال مفعّل — المتدرب والمدرب والمشرف العام', function (): void {
    $service = app(ImpersonationService::class);

    foreach ([$this->participant, $this->trainer, $this->supervisor] as $target) {
        expect($service->refusalReason($this->sysadmin, $target))->toBeNull()
            ->and($service->canPreview($this->sysadmin, $target))->toBeTrue();
    }
});
