<?php

declare(strict_types=1);

/**
 * Opening and closing a cohort's registration — the general supervisor's,
 * from the registrations screen (D-117).
 *
 * The owner put opening, closing and accepting in one person's hands, so the
 * switch left the landing editor. What is asked of the server here:
 *
 *  · the supervisor moves it, the registration form obeys it on the next
 *    request, and the trail records it before it is saved (art. 8);
 *  · nobody else moves it — not the system administrator who owns the landing
 *    page, not a trainer, a coordinator or a participant, not a preview;
 *  · a running or finished cohort is not offered a switch that changes
 *    nothing, and a value that is not a boolean is refused, never read as
 *    "closed";
 *  · a cohort with no settings row is open by the switch, on every screen
 *    that reads it (LandingSetting::switchIsOn).
 *
 * @see BR-07, BR-31, BR-33 · PRD §9.1.2, §9.2.3, §9.18 · CONSTITUTION Art. 5, Art. 8 · D-117
 */

use App\Models\AuditLog;
use App\Models\LandingSetting;
use App\Models\User;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 12:00:00'));

    // A cohort that takes registrations on every count but the switch.
    $this->cohort = makeCohort([
        'status' => 'open',
        'start_date' => '2026-10-20',
        'end_date' => '2026-11-20',
        'registration_closes_at' => null,
    ]);
    $this->supervisor = makeAdmin();
    $this->sysadmin = makeSystemAdmin();
});

function intakeSwitch(object $test, User $actor, mixed $open): Illuminate\Testing\TestResponse
{
    return $test->actingAs($actor)->put(route('admin.registrations.intake', $test->cohort), ['open' => $open]);
}

function intakeIsOpen(object $test): bool
{
    return LandingSetting::switchIsOn(LandingSetting::query()->forCohort($test->cohort)->first());
}

it('D-117: المشرف العام يغلق التسجيل ثم يفتحه، والنموذج يطيعه في الطلب التالي، ويُسجَّل كل تغيير', function (): void {
    expect(intakeIsOpen($this))->toBeTrue()
        ->and($this->cohort->fresh()->load('landingSetting')->acceptsRegistrations())->toBeTrue();

    intakeSwitch($this, $this->supervisor, '0')
        ->assertRedirect(route('admin.registrations.index').'#intake')
        ->assertSessionHas('status', __('admin.registrations.intake.closed', ['cohort' => $this->cohort->name]));

    // A cohort with no settings row got the row that says "closed".
    expect(LandingSetting::query()->forCohort($this->cohort)->sole()->is_registration_open)->toBeFalse()
        ->and($this->cohort->fresh()->load('landingSetting')->acceptsRegistrations())->toBeFalse();

    auth()->logout();
    $this->get(route('home'))->assertSee('data-registration="closed"', escape: false);

    intakeSwitch($this, $this->supervisor, '1')
        ->assertSessionHas('status', __('admin.registrations.intake.opened', ['cohort' => $this->cohort->name]));

    auth()->logout();
    $this->get(route('home'))->assertSee('data-registration="open"', escape: false);

    $trail = AuditLog::query()->where('action', 'registration.intake_changed')->orderBy('created_at')->get();

    expect($trail)->toHaveCount(2)
        ->and($trail->pluck('actor_id')->unique()->all())->toBe([$this->supervisor->id])
        ->and($trail->pluck('entity_id')->unique()->all())->toBe([$this->cohort->id])
        ->and($trail->map(fn (AuditLog $row): array => [$row->before['is_registration_open'], $row->after['is_registration_open']])->all())
        ->toBe([[true, false], [false, true]]);
});

it('D-117: طلب الحالة القائمة لا يغيّر شيئًا ولا يكتب سطرًا في السجل', function (): void {
    intakeSwitch($this, $this->supervisor, '1')
        ->assertSessionHas('status', __('admin.registrations.intake.unchanged'));

    expect(LandingSetting::query()->forCohort($this->cohort)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'registration.intake_changed')->count())->toBe(0);
});

it('D-117: 403 — لا يحرّك المفتاح مدير النظام ولا المدرب ولا المنسّق ولا المتدرب، ويُسجَّل كل رفض', function (): void {
    $actors = [
        $this->sysadmin,
        makeTrainer($this->cohort),
        makeCoordinator($this->cohort),
        makeParticipant($this->cohort),
    ];

    foreach ($actors as $actor) {
        intakeSwitch($this, $actor, '0')->assertForbidden();
    }

    expect(intakeIsOpen($this))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'registration.intake_changed')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'access.denied')->count())->toBeGreaterThanOrEqual(count($actors));
});

it('BR-33: معاينة حساب المشرف العام لا تحرّك مفتاح التسجيل — 403', function (): void {
    $this->actingAs($this->sysadmin)->post(route('admin.users.preview', $this->supervisor))->assertRedirect();

    $this->put(route('admin.registrations.intake', $this->cohort), ['open' => '0'])->assertForbidden();

    expect(intakeIsOpen($this))->toBeTrue();
});

it('D-117: دفعة بدأت أو انتهت لا يُحرَّك مفتاحها، وقيمة غير منطقية تُرفض ولا تُقرأ «مغلق»', function (): void {
    foreach (['running', 'completed'] as $status) {
        $this->cohort->forceFill(['status' => $status])->save();

        intakeSwitch($this, $this->supervisor, '0')
            ->assertSessionHasErrors(['open' => __('admin.registrations.intake.not_governed')]);
    }

    $this->cohort->forceFill(['status' => 'open'])->save();

    foreach (['garbage', '', null, 'yes', '2'] as $value) {
        intakeSwitch($this, $this->supervisor, $value)->assertSessionHasErrors('open');
    }

    expect(intakeIsOpen($this))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'registration.intake_changed')->count())->toBe(0);
});

it('D-117: شاشة طلبات التسجيل تعرض المفتاح للدفعات القادمة والمفتوحة وحدها، وتقول هل يستقبل النموذج الآن', function (): void {
    $upcoming = makeCohort(['status' => 'upcoming']);
    LandingSetting::factory()->create(['cohort_id' => $upcoming->id, 'is_registration_open' => false]);
    $running = makeCohort(['status' => 'running']);

    $this->actingAs($this->supervisor)
        ->get(route('admin.registrations.index'))
        ->assertOk()
        ->assertSee(__('admin.registrations.intake.title'))
        ->assertSee(route('admin.registrations.intake', $this->cohort), false)
        ->assertSee(route('admin.registrations.intake', $upcoming), false)
        ->assertDontSee(route('admin.registrations.intake', $running), false)
        ->assertSee(__('admin.registrations.intake.note_accepting'))
        ->assertSee(__('admin.registrations.intake.note_closed'))
        ->assertSee(__('admin.registrations.intake.open'))
        ->assertSee(__('admin.registrations.intake.close'));
});

it('D-117: بلا دفعة قادمة أو مفتوحة تشرح البطاقة ذلك وتدلّ على شاشة الدفعات', function (): void {
    $this->cohort->forceFill(['status' => 'completed'])->save();

    $this->actingAs($this->supervisor)
        ->get(route('admin.registrations.index'))
        ->assertOk()
        ->assertSee(__('admin.registrations.intake.empty_title'))
        ->assertSee(route('admin.cohorts.index'), false);
});
