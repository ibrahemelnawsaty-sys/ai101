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
    $this->actingAs($this->admin)->post(route('admin.finalProject.store'), [
        'cohort_id' => $this->cohort->id,
        'title' => 'Final project',
        'brief' => 'Brief',
        'due_at' => '2026-09-30T23:59',
        'max_score' => 50,
        'is_available' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $fresh = $this->project->fresh();

    expect($fresh->is_available)->toBeTrue()
        ->and($fresh->is_unlocked)->toBeFalse()
        ->and(Notification::query()->where('user_id', $this->coordinator->id)->where('type', 'final_project_available')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $this->participant->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'final_project.made_available')->where('actor_id', $this->admin->id)->count())->toBe(1);

    $this->actingAs($this->participant)->post(route('finalProject.submit'), handInPayload($this->project))->assertForbidden();
});

it('D-127, BR-15: المنسّق الأساسي لا ينشر قبل الإتاحة — 403 ولا يتغيّر شيء', function (): void {
    pressProjectPublication($this, $this->coordinator, true)->assertForbidden();

    expect($this->project->fresh()->is_unlocked)->toBeFalse();
});

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

it('D-127: إلغاء الإتاحة يقفل المشروع المنشور فورًا، والتسليمات السابقة محفوظة', function (): void {
    $this->project->update(['is_available' => true, 'is_unlocked' => true]);
    makeProjectSubmission($this->project, $this->participant);

    $this->actingAs($this->admin)->post(route('admin.finalProject.store'), [
        'cohort_id' => $this->cohort->id,
        'title' => 'Final project',
        'brief' => 'Brief',
        'due_at' => '2026-09-30T23:59',
        'max_score' => 50,
    ])->assertSessionHasNoErrors();

    $fresh = $this->project->fresh();

    expect($fresh->is_available)->toBeFalse()
        ->and($fresh->is_unlocked)->toBeFalse()
        ->and(ProjectSubmission::query()->where('final_project_id', $this->project->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'final_project.availability_withdrawn')->count())->toBe(1);

    // The coordinator cannot re-open it until it is made available again.
    pressProjectPublication($this, $this->coordinator, true)->assertForbidden();
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
