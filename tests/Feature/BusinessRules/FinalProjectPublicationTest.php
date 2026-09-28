<?php

declare(strict_types=1);

/**
 * D-127 — the final project opens in two steps: the general supervisor makes
 * it available, the cohort's PRIMARY coordinator publishes it. BR-15/BR-16's
 * lock is unchanged — it still reads `is_unlocked`, which only the publish
 * press sets now.
 *
 * @see D-127 · D-124 · BR-15, BR-16, BR-19 · CONSTITUTION Art. 5, Art. 7, Art. 8
 */

use App\Events\FinalProjectUnlocked;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\ProjectSubmission;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-28 12:00:00'));

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
    $this->trainer = makeTrainer($this->cohort);
    $this->coordinator = makeCoordinator($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->project = makeFinalProject($this->cohort, ['is_unlocked' => false, 'is_available' => false]);
});

/** Press the coordinator's publish switch for the project. */
function pressProjectPublication(object $test, object $actor, bool $published, array $extra = []): Illuminate\Testing\TestResponse
{
    return $test->actingAs($actor)->put(
        route('coordinator.finalProject.publication', $test->project),
        ['published' => $published ? '1' : '0'] + $extra,
    );
}

it('D-127: إتاحة المشرف العام لا تفتح المشروع للمتدربين، وتُشعر المنسّق الأساسي وحده وتُسجَّل', function (): void {
    $this->actingAs($this->admin)
        ->put(route('admin.finalProject.availability', $this->project), ['available' => '1'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.final_project.made_available'));

    $fresh = $this->project->fresh();

    expect($fresh->is_available)->toBeTrue()
        ->and($fresh->is_unlocked)->toBeFalse()
        ->and(Notification::query()->where('user_id', $this->coordinator->id)->where('type', 'final_project_available')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $this->participant->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'final_project.made_available')->where('actor_id', $this->admin->id)->count())->toBe(1);

    $this->actingAs($this->participant)->post(route('finalProject.submit'), handInPayload($this->project))->assertForbidden();
});

it('D-127, BR-15: المنسّق الأساسي لا ينشر قبل الإتاحة — يُرفض برسالة تشرح السبب ولا يتغيّر شيء', function (): void {
    pressProjectPublication($this, $this->coordinator, true)
        ->assertSessionHas('error', __('coordinator.final_project.errors.not_available'));

    expect($this->project->fresh()->is_unlocked)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'like', 'access.denied%')->count())->toBe(0);
});

it('D-127: الضغط المزدوج على «أوقف النشر» لا يُظهر 403 ولا يُسجَّل رفضًا — يقول إن شيئًا لم يتغيّر', function (): void {
    $this->project->update(['is_available' => true, 'is_unlocked' => true]);

    pressProjectPublication($this, $this->coordinator, false)->assertSessionHas('status');
    pressProjectPublication($this, $this->coordinator, false)->assertSessionHas('warning', __('coordinator.final_project.unchanged'));

    expect(AuditLog::query()->where('action', 'like', 'access.denied%')->count())->toBe(0);
});

it('D-128: حساب المشرف العام لا ينشر ولو أُجلس منسّقًا أساسيًا للدفعة — 403 حتى يقرّر المالك', function (): void {
    $this->project->update(['is_available' => true]);
    enroll($this->admin, $this->cohort, 'coordinator');
    $this->cohort->update(['primary_coordinator_id' => $this->admin->id]);

    pressProjectPublication($this, $this->admin, true)->assertForbidden();

    expect($this->project->fresh()->is_unlocked)->toBeFalse();
})->group('authz');

it('D-127: المنسّق الأساسي ينشر بعد الإتاحة فيُفتح المشروع ويُعلَن للمتدربين مرة واحدة', function (): void {
    Event::fake([FinalProjectUnlocked::class]);
    $this->project->update(['is_available' => true]);

    pressProjectPublication($this, $this->coordinator, true)->assertRedirect();
    pressProjectPublication($this, $this->coordinator, true)->assertRedirect();

    $fresh = $this->project->fresh();

    expect($fresh->is_unlocked)->toBeTrue()
        ->and($fresh->unlocked_by)->toBe($this->coordinator->id)
        ->and(AuditLog::query()->where('action', 'final_project.published')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $this->participant->id)->where('type', 'final_project_unlocked')->count())->toBe(1);

    Event::assertDispatchedTimes(FinalProjectUnlocked::class, 1);
    Event::assertDispatched(FinalProjectUnlocked::class, fn (FinalProjectUnlocked $event): bool => $event->withGuide === false);
});

it('D-127: لا المشرف العام ولا المدرب ولا المتدرب ولا منسّق غير أساسي ينشر المشروع — 403', function (): void {
    $this->project->update(['is_available' => true]);

    // A second coordinator, then the first chosen as primary: the second is not.
    $second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->coordinator->id]);

    foreach ([$this->admin, $this->trainer, $this->participant, $second] as $actor) {
        pressProjectPublication($this, $actor, true)->assertForbidden();
        $this->flushSession();
    }

    expect($this->project->fresh()->is_unlocked)->toBeFalse();
})->group('authz');

it('D-127, D-124: دفعة بلا منسّق أساسي لا يُنشر مشروعها — 403، والمشرف يرى السبب', function (): void {
    $this->project->update(['is_available' => true]);
    makeCoordinator($this->cohort); // two coordinators, none chosen: nobody is primary

    pressProjectPublication($this, $this->coordinator, true)->assertForbidden();

    expect($this->project->fresh()->is_unlocked)->toBeFalse();

    $this->actingAs($this->admin)
        ->get(route('admin.finalProject.index', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(__('admin.final_project.no_primary_coordinator'));
})->group('authz');

it('D-127: حفظ إعدادات المشروع لا يمسّ إتاحته — حفظ من تبويب قديم لا يقفل مشروعًا منشورًا', function (): void {
    $this->project->update(['is_available' => true, 'is_unlocked' => true]);

    $this->actingAs($this->admin)->post(route('admin.finalProject.store'), [
        'cohort_id' => $this->cohort->id,
        'title' => 'Final project',
        'brief' => 'Brief',
        'due_at' => '2026-09-30T23:59',
        'max_score' => 50,
    ])->assertSessionHasNoErrors();

    expect($this->project->fresh()->is_available)->toBeTrue()
        ->and($this->project->fresh()->is_unlocked)->toBeTrue();
});

it('D-127: إلغاء الإتاحة يقفل المشروع المنشور فورًا بعد تأكيد صريح حين وصلت تسليمات، والتسليمات محفوظة', function (): void {
    $this->project->update(['is_available' => true, 'is_unlocked' => true]);
    makeProjectSubmission($this->project, $this->participant);

    $this->actingAs($this->admin)
        ->put(route('admin.finalProject.availability', $this->project), ['available' => '0'])
        ->assertSessionHasErrors('confirmed');

    expect($this->project->fresh()->is_unlocked)->toBeTrue();

    $this->actingAs($this->admin)
        ->put(route('admin.finalProject.availability', $this->project), ['available' => '0', 'confirmed' => '1'])
        ->assertSessionHas('status', __('admin.final_project.withdrawn_locked'));

    $fresh = $this->project->fresh();

    expect($fresh->is_available)->toBeFalse()
        ->and($fresh->is_unlocked)->toBeFalse()
        ->and(ProjectSubmission::query()->where('final_project_id', $this->project->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'final_project.availability_withdrawn')->count())->toBe(1);

    // The coordinator cannot re-open it until it is made available again.
    pressProjectPublication($this, $this->coordinator, true)
        ->assertSessionHas('error', __('coordinator.final_project.errors.not_available'));
    expect($this->project->fresh()->is_unlocked)->toBeFalse();
});

it('D-127: إيقاف النشر بعد وصول تسليمات يطلب تأكيدًا صريحًا، ولا يمسّ التسليمات', function (): void {
    $this->project->update(['is_available' => true, 'is_unlocked' => true]);
    makeProjectSubmission($this->project, $this->participant);

    pressProjectPublication($this, $this->coordinator, false)->assertSessionHasErrors('confirmed');
    expect($this->project->fresh()->is_unlocked)->toBeTrue();

    pressProjectPublication($this, $this->coordinator, false, ['confirmed' => '1'])->assertSessionHasNoErrors();

    expect($this->project->fresh()->is_unlocked)->toBeFalse()
        ->and($this->project->fresh()->is_available)->toBeTrue()
        ->and(ProjectSubmission::query()->where('final_project_id', $this->project->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'final_project.unpublished')->where('actor_id', $this->coordinator->id)->count())->toBe(1);
});

it('D-127: تبويب المنسّق يعرض زر النشر للمنسّق الأساسي وحده، ويعرض للمنسّق الآخر أنه للاطلاع', function (): void {
    $this->project->update(['is_available' => true]);
    $second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->coordinator->id]);

    $this->actingAs($this->coordinator)
        ->get(route('coordinator.finalProject', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(__('coordinator.final_project.publish'));

    $this->actingAs($second)
        ->get(route('coordinator.finalProject', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertDontSee(__('coordinator.final_project.publish'))
        ->assertSee(__('coordinator.final_project.primary_only'));
});

it('D-127: منسّق دفعة أخرى لا يقرأ تبويب هذه الدفعة ولا ينشر مشروعها — 403', function (): void {
    $this->project->update(['is_available' => true]);
    $stranger = makeCoordinator(makeCohort());

    $this->actingAs($stranger)
        ->get(route('coordinator.finalProject', ['cohort' => $this->cohort->id]))
        ->assertForbidden();

    pressProjectPublication($this, $stranger, true)->assertForbidden();

    expect($this->project->fresh()->is_unlocked)->toBeFalse();
})->group('authz');

it('D-127: تبويب المنسّق يعرض «أوقف النشر» لا «انشر» حين يكون المشروع والدليل منشورين', function (): void {
    $this->project->update(['is_available' => true, 'is_unlocked' => true]);
    App\Models\FinalProjectGuide::factory()->published()->create(['final_project_id' => $this->project->id]);

    $this->actingAs($this->coordinator)
        ->get(route('coordinator.finalProject', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertDontSee(__('coordinator.final_project.publish'))
        ->assertSee(__('coordinator.final_project.unpublish'))
        ->assertDontSee(__('coordinator.final_project.guide_publish'))
        ->assertSee(__('coordinator.final_project.guide_unpublish'));
});

it('D-127: شاشة تفضيلات المتدرب لا تعرض إشعارات الفريق، والمنسّق يرى إشعارات النشر ويقدر يكتمها', function (): void {
    $staff = ['final_project_available', 'final_project_guide_available', 'final_project_guide_published_staff'];

    expect(array_intersect(App\Support\NotificationTypes::forRole('participant'), $staff))->toBe([])
        ->and(App\Support\NotificationTypes::forRole('participant'))->toContain('final_project_guide_published')
        ->and(App\Support\NotificationTypes::forRole('coordinator'))->toContain(...$staff)
        ->and(App\Support\NotificationTypes::forRole('trainer'))->toContain('final_project_guide_published_staff');

    // No preference description prints a raw placeholder.
    foreach ($staff as $type) {
        expect((string) __('notifications.types.'.$type.'.body'))->not->toContain(':');
    }
});

it('D-127, المادة 17: تبويب المنسّق لدفعة بلا مشروع يعرض حالة فارغة خاصة به تدلّ على لوحته', function (): void {
    $cohort = makeCohort();
    $coordinator = makeCoordinator($cohort);

    $this->actingAs($coordinator)
        ->get(route('coordinator.finalProject', ['cohort' => $cohort->id]))
        ->assertOk()
        ->assertSee(__('coordinator.final_project.no_project_title'))
        ->assertSee(__('coordinator.final_project.no_project_body'))
        ->assertSee(route('coordinator.dashboard'));
});
