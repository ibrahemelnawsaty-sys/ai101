<?php

declare(strict_types=1);

/**
 * The system administrator (D-117): the accounts, the account preview and the
 * landing page — and nothing else. The general supervisor keeps everything
 * else and loses exactly those three.
 *
 * Every refusal here is asked of the server, never of a hidden button
 * (CONSTITUTION art. 5): a direct request is made and its answer asserted, and
 * a refused request must leave a row in the trail with its IP address
 * (art. 22).
 *
 * @see BR-28, BR-31, BR-32, BR-33, BR-35 · PRD §4.2, §4.5 · CONSTITUTION Art. 5, Art. 22 · D-117
 */

use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\LandingSetting;
use App\Models\Resource;
use App\Models\User;
use App\Services\Permissions\RoleResolver;
use App\Support\NotificationTypes;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->cohort = makeCohort(['status' => 'open']);
    $this->supervisor = makeAdmin();
    $this->sysadmin = makeSystemAdmin();
    $this->participant = makeParticipant($this->cohort);
});

/** How many refusals the trail holds for one actor, each with an IP address. */
function systemAdminRoleDeniedRows(User $actor): int
{
    return AuditLog::query()
        ->where('actor_id', $actor->id)
        ->where('action', App\Services\Audit\AuditLogger::ACCESS_DENIED)
        ->whereNotNull('ip_address')
        ->count();
}

/*
|--------------------------------------------------------------------------
| What the system administrator reaches
|--------------------------------------------------------------------------
*/

it('D-117: مدير النظام يبدأ من قائمة المستخدمين لا من لوحة المشارك', function (): void {
    $this->actingAs($this->sysadmin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.users.index'));

    $this->actingAs($this->sysadmin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('lang="ar"', false)
        ->assertSee(__('enums.user_role.system_admin'), false)
        ->assertSee(__('enums.user_role.admin'), false);
});

it('D-117: مدير النظام يرى صفحة كل حساب ويدعو ويغيّر الدور ويعطّل', function (): void {
    $this->actingAs($this->sysadmin)->get(route('admin.users.show', $this->participant))->assertOk();

    // An invitation needs no cohort for the two administrative roles.
    foreach (['admin' => 'new-supervisor@example.test', 'system_admin' => 'new-sysadmin@example.test'] as $role => $email) {
        $this->actingAs($this->sysadmin)
            ->post(route('admin.users.store'), [
                'email' => $email,
                'role' => $role,
                'status' => 'active',
                'first_name_ar' => 'خالد', 'second_name_ar' => 'محمد', 'third_name_ar' => 'علي', 'last_name_ar' => 'العتيبي',
            ])
            ->assertSessionHasNoErrors();

        expect(User::query()->where('email', $email)->sole()->role->value)->toBe($role);
    }

    $trainer = makeTrainer($this->cohort);

    assertAccepted($this->actingAs($this->sysadmin)->put(route('admin.users.role', $trainer), [
        'role' => 'coordinator',
        'reason' => 'Moving this trainer to attendance duty.',
    ]));
    assertAccepted($this->actingAs($this->sysadmin)->patch(route('admin.users.status', $trainer), [
        'status' => 'suspended',
    ]));

    $fresh = $trainer->fresh();

    expect($fresh->role->value)->toBe('coordinator')
        ->and($fresh->status->value)->toBe('suspended');
});

it('D-117: زر المعاينة نموذج POST يبدأ المعاينة فعلًا — رابط GET كان يُجاب 405', function (): void {
    $page = $this->actingAs($this->sysadmin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->getContent();

    $action = route('admin.users.preview', $this->participant);

    // The route answers a GET with 405: the link that stood here could never
    // start a preview. The screen must post to it.
    expect($page)->toContain('action="'.$action.'"')
        ->and($page)->not->toContain('href="'.$action.'"');

    $this->actingAs($this->sysadmin)->get($action)->assertStatus(405);

    $this->actingAs($this->sysadmin)->post($action)->assertRedirect(route('dashboard'));

    expect(ImpersonationSession::query()->whereNull('ended_at')->where('target_id', $this->participant->id)->count())->toBe(1);
});

it('BR-35: خدمة المعاينة تعيد فحص القواعد بنفسها — لا تثق بالسياسة وحدها — وتسجّل كل رفض', function (): void {
    $service = app(App\Services\Permissions\ImpersonationService::class);
    $otherSysadmin = makeSystemAdmin();
    $deleted = makeParticipant($this->cohort);
    $deleted->forceFill(['status' => 'deleted'])->save();

    // The one permitted shape, and every refused one.
    expect($service->canPreview($this->sysadmin, $this->supervisor))->toBeTrue()
        ->and($service->canPreview($this->sysadmin, $this->participant))->toBeTrue()
        ->and($service->canPreview($this->sysadmin, $this->sysadmin))->toBeFalse()
        ->and($service->canPreview($this->sysadmin, $otherSysadmin))->toBeFalse()
        ->and($service->canPreview($this->sysadmin, $deleted->fresh()))->toBeFalse()
        ->and($service->canPreview($this->supervisor, $this->participant))->toBeFalse();

    // Called directly, past any policy, a refused start writes nothing but its
    // refusal to the trail.
    expect(fn () => $service->start($this->sysadmin, $otherSysadmin))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);

    expect(ImpersonationSession::query()->count())->toBe(0)
        ->and(AuditLog::query()
            ->where('action', App\Services\Audit\AuditLogger::ACCESS_DENIED)
            ->where('entity_id', $otherSysadmin->id)
            ->where('actor_id', $this->sysadmin->id)
            ->count())->toBe(1);
});

it('D-117: انتهاء المعاينة يعيد مدير النظام إلى قائمة المستخدمين لا إلى لوحة المشرف', function (): void {
    $this->actingAs($this->sysadmin)->post(route('admin.users.preview', $this->participant));

    freezeAt(riyadhAt('2026-10-12 12:30:01'));

    $this->get(route('schedule'))->assertRedirect(route('admin.users.index'));
    $this->assertAuthenticatedAs($this->sysadmin->fresh());

    $this->get(route('admin.users.index'))->assertOk();
});

it('D-117: مدير النظام يحرّر صفحة الهبوط', function (): void {
    LandingSetting::factory()->create(['cohort_id' => $this->cohort->id, 'hero_text' => 'CANARY-BEFORE', 'is_registration_open' => true]);

    $this->actingAs($this->sysadmin)->get(route('admin.landing.edit'))->assertOk();

    assertAccepted($this->actingAs($this->sysadmin)->put(route('admin.landing.update'), [
        'settings' => ['hero_subtitle' => 'CANARY-AFTER', 'is_registration_open' => true, 'countdown_enabled' => false],
    ]));

    $this->get(route('home'))->assertSee('CANARY-AFTER', false);
});

/*
|--------------------------------------------------------------------------
| What the system administrator does not reach
|--------------------------------------------------------------------------
*/

it('D-117: 403 — مدير النظام لا يصل شيئًا من شاشات المشرف ولا المدرب ولا المنسّق ولا المتدرب، ويُسجَّل كل رفض', function (): void {
    $cohort = ['cohort' => $this->cohort->id];

    $refused = [
        route('admin.dashboard'),
        route('admin.programs.index'),
        route('admin.cohorts.index'),
        route('admin.registrations.index'),
        route('admin.certificates.index'),
        route('admin.broadcasts.index'),
        route('admin.finalProject.index'),
        route('admin.reports.index'),
        route('admin.audit.index'),
        route('admin.settings.edit'),
        route('trainer.dashboard', $cohort),
        route('trainer.attendance', $cohort),
        route('trainer.sessions', $cohort),
        route('coordinator.dashboard', $cohort),
        route('participant.card'),
        route('grades'),
    ];

    foreach ($refused as $url) {
        $this->actingAs($this->sysadmin)->get($url)->assertForbidden();
    }

    // Writes too, with a body that would otherwise pass.
    $this->actingAs($this->sysadmin)
        ->post(route('admin.cohorts.participants.attach', $this->cohort), ['email' => $this->participant->email])
        ->assertForbidden();
    $this->actingAs($this->sysadmin)
        ->put(route('admin.settings.update'), ['locale' => 'ar', 'timezone' => 'Asia/Riyadh'])
        ->assertForbidden();

    expect(systemAdminRoleDeniedRows($this->sysadmin))->toBeGreaterThanOrEqual(count($refused) + 1);
});

it('D-117: 403 — شاشات الدفعة المشتركة مغلقة على مدير النظام، وحسابه هو مفتوح له', function (): void {
    $start = riyadhAt('2026-10-12 18:00:00');
    $session = sessionInCohort($this->cohort, $start, $start->addHours(2));
    $assignment = makeAssignment($this->cohort);
    $resource = Resource::factory()->create([
        'cohort_id' => $this->cohort->id,
        'type' => 'link',
        'external_url' => 'https://example.test/reading',
    ]);
    $thread = makeThreadFor($this->participant, $this->cohort);

    $refused = [
        ['get', route('schedule')],
        ['get', route('schedule.ics')],
        ['get', route('attendance.index')],
        ['get', route('attendance.export')],
        ['get', route('live')],
        ['post', route('live.join', $session)],
        ['get', route('assignments.index')],
        ['get', route('assignments.show', $assignment)],
        ['get', route('resources.index')],
        ['get', route('resources.download', $resource)],
        ['get', route('messages.index')],
        ['get', route('messages.poll', $thread)],
        ['post', route('cohort.switch'), ['cohort_id' => $this->cohort->id]],
    ];

    foreach ($refused as $request) {
        [$verb, $url] = $request;
        $this->actingAs($this->sysadmin)->{$verb}($url, $request[2] ?? [])->assertForbidden();
    }

    expect(systemAdminRoleDeniedRows($this->sysadmin))->toBeGreaterThanOrEqual(count($refused));

    // Their own account stays theirs.
    $this->actingAs($this->sysadmin)->get(route('profile'))->assertOk();
    $this->actingAs($this->sysadmin)->get(route('notifications'))->assertOk();

    // The same screens for someone in the cohort — the control.
    $this->actingAs($this->participant)->get(route('resources.index'))->assertOk();
    $this->actingAs($this->supervisor)->get(route('schedule'))->assertOk();
});

it('D-117: التحاق قديم لا يمنح مدير النظام شيئًا بعد تغيير دوره', function (): void {
    // A trainee who becomes the system administrator keeps the enrolment row.
    $former = makeParticipant($this->cohort);
    $former->forceFill(['role' => 'system_admin'])->save();
    $former = $former->fresh();

    $roles = new RoleResolver;

    expect($roles->effectiveRoles($former))->toBe(['system_admin'])
        ->and($roles->participantCohortIds($former))->toBe([])
        ->and($roles->readableCohortIds($former))->toBe([])
        ->and($roles->canReachCohort($former, $this->cohort->id))->toBeFalse()
        ->and($former->accessibleCohortIds())->toBe([])
        ->and($roles->shellRole($former))->toBe('system_admin');

    $this->actingAs($former)->get(route('participant.card'))->assertForbidden();
    $this->actingAs($former)->get(route('grades'))->assertForbidden();
});

it('D-117: تصدير قائمة الحسابات مرفوض على الجميع ولا زر له', function (): void {
    foreach ([$this->sysadmin, $this->supervisor] as $actor) {
        $this->actingAs($actor)->get(route('admin.users.export'))->assertForbidden();
    }

    $this->actingAs($this->sysadmin)
        ->get(route('admin.users.index'))
        ->assertDontSee(route('admin.users.export'), false);
});

it('D-117: لا إشعارات لمدير النظام، وصفحة ملفه تعرض حالتها الفارغة', function (): void {
    expect(NotificationTypes::forRole('system_admin'))->toBe([]);

    $this->actingAs($this->sysadmin)
        ->get(route('profile'))
        ->assertOk()
        ->assertSee(__('notifications.preferences_empty_title'), false);
});

/*
|--------------------------------------------------------------------------
| What the general supervisor lost, and what it kept
|--------------------------------------------------------------------------
*/

it('D-117: 403 — المشرف العام لا يصل الحسابات ولا المعاينة ولا صفحة الهبوط، ويُسجَّل كل رفض', function (): void {
    $gets = [
        route('admin.users.index'),
        route('admin.users.create'),
        route('admin.users.import'),
        route('admin.users.show', $this->participant),
        route('admin.landing.edit'),
    ];

    foreach ($gets as $url) {
        $this->actingAs($this->supervisor)->get($url)->assertForbidden();
    }

    $this->actingAs($this->supervisor)
        ->post(route('admin.users.store'), ['email' => 'nobody@example.test', 'role' => 'trainer'])
        ->assertForbidden();
    $this->actingAs($this->supervisor)
        ->put(route('admin.users.role', $this->participant), ['role' => 'trainer', 'reason' => 'A promotion by the supervisor.'])
        ->assertForbidden();
    $this->actingAs($this->supervisor)
        ->patch(route('admin.users.status', $this->participant), ['status' => 'suspended'])
        ->assertForbidden();
    $this->actingAs($this->supervisor)
        ->post(route('admin.users.preview', $this->participant))
        ->assertForbidden();
    $this->actingAs($this->supervisor)
        ->put(route('admin.landing.update'), ['settings' => ['hero_subtitle' => 'CANARY-TAMPERED']])
        ->assertForbidden();

    $fresh = $this->participant->fresh();

    expect(User::query()->where('email', 'nobody@example.test')->exists())->toBeFalse()
        ->and($fresh->role->value)->toBe('participant')
        ->and($fresh->status->value)->toBe('active')
        ->and(ImpersonationSession::query()->count())->toBe(0)
        ->and(systemAdminRoleDeniedRows($this->supervisor))->toBeGreaterThanOrEqual(count($gets) + 5);
});

it('D-117: المشرف العام يحتفظ بلوحته وإعدادات المنصة — لكل منهما صلاحيته الخاصة', function (): void {
    $this->actingAs($this->supervisor)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($this->supervisor)->get(route('admin.settings.edit'))->assertOk();
    $this->actingAs($this->supervisor)->get(route('admin.cohorts.index'))->assertOk();

    assertAccepted($this->actingAs($this->supervisor)->put(route('admin.settings.update'), [
        'locale' => 'ar',
        'timezone' => 'Asia/Riyadh',
    ]));

    expect(AuditLog::query()->where('action', 'settings.updated')->where('actor_id', $this->supervisor->id)->count())->toBe(1);
});

it('D-117: قائمة المشرف العام بلا الحسابات وصفحة الهبوط، وقائمة مدير النظام بهما وحدهما', function (): void {
    $supervisorRail = $this->actingAs($this->supervisor)->get(route('admin.dashboard'))->assertOk()->getContent();
    $sysadminRail = $this->actingAs($this->sysadmin)->get(route('admin.users.index'))->assertOk()->getContent();

    expect($supervisorRail)->not->toContain('href="'.route('admin.users.index').'"')
        ->and($supervisorRail)->not->toContain('href="'.route('admin.landing.edit').'"')
        ->and($sysadminRail)->toContain('href="'.route('admin.users.index').'"')
        ->and($sysadminRail)->toContain('href="'.route('admin.landing.edit').'"')
        ->and($sysadminRail)->not->toContain('href="'.route('admin.dashboard').'"');
});

/*
|--------------------------------------------------------------------------
| athar:change-role — the one door to the first system administrator
|--------------------------------------------------------------------------
*/

it('D-117: أمر تغيير الدور يرفض الدور المجهول والعنوان المجهول والسبب القصير ولا يكتب شيئًا', function (): void {
    $this->artisan('athar:change-role', ['email' => $this->participant->email, 'role' => 'owner', '--force' => true])
        ->expectsOutputToContain('admin, system_admin, trainer, coordinator, participant')
        ->assertFailed();

    $this->artisan('athar:change-role', ['email' => 'nobody@example.test', 'role' => 'trainer', '--force' => true])
        ->assertFailed();

    $this->artisan('athar:change-role', ['email' => $this->participant->email, 'role' => 'trainer', '--reason' => 'short', '--force' => true])
        ->assertFailed();

    expect($this->participant->fresh()->role->value)->toBe('participant')
        ->and(AuditLog::query()->where('action', 'user.role_changed')->count())->toBe(0);
});

it('D-117: أمر تغيير الدور لا يكتب شيئًا للدور نفسه ولا حين يُرفض التأكيد', function (): void {
    $this->artisan('athar:change-role', ['email' => $this->participant->email, 'role' => 'participant'])
        ->expectsOutputToContain('already holds the role participant')
        ->assertSuccessful();

    $this->artisan('athar:change-role', ['email' => $this->participant->email, 'role' => 'trainer'])
        ->expectsQuestion('Reason for the change (written to the audit trail)', 'Moving this trainee to the training team.')
        ->expectsConfirmation('Change '.$this->participant->email.' from participant to trainer?', 'no')
        ->assertFailed();

    expect($this->participant->fresh()->role->value)->toBe('participant')
        ->and(AuditLog::query()->where('action', 'user.role_changed')->count())->toBe(0);
});

it('D-117: أمر تغيير الدور يسجّل في سجل التدقيق قبل الحفظ، بالسبب والطريق', function (): void {
    $this->artisan('athar:change-role', ['email' => strtoupper($this->participant->email), 'role' => 'trainer'])
        ->expectsQuestion('Reason for the change (written to the audit trail)', 'Moving this trainee to the training team.')
        ->expectsConfirmation('Change '.$this->participant->email.' from participant to trainer?', 'yes')
        ->assertSuccessful();

    $row = AuditLog::query()->where('action', 'user.role_changed')->where('entity_id', $this->participant->id)->sole();

    expect($this->participant->fresh()->role->value)->toBe('trainer')
        ->and($row->before)->toBe(['role' => 'participant'])
        ->and($row->after)->toBe([
            'role' => 'trainer',
            'reason' => 'Moving this trainee to the training team.',
            'via' => 'console',
        ]);
});
