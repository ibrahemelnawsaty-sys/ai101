<?php

declare(strict_types=1);

/**
 * The cohort's primary coordinator — the one a support ticket reaches first
 * (D-124). The owner's rule, each part asked of the server:
 *
 *  · a single coordinator is primary on their own; with several, the general
 *    supervisor chooses, among the cohort's coordinators and nobody else;
 *  · a cohort is neither opened for registration nor started without one,
 *    and a new cohort is created "upcoming" for that reason;
 *  · the last coordinator is not removed, nor the primary one while a choice
 *    remains; a coordinator who left is never treated as primary.
 *
 * Refusals are validation errors on the page (302 + the reason), so the
 * supervisor is told what to do first.
 *
 * @see D-124 · D-105 · D-117 · CONSTITUTION art. 5, art. 7, art. 22
 */

use App\Enums\EnrollmentStatus;
use App\Models\AuditLog;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\LandingSetting;
use App\Models\User;
use App\Services\Cohorts\PrimaryCoordinator;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 12:00:00'));

    $this->cohort = makeCohort(['status' => 'upcoming', 'start_date' => '2026-10-20', 'end_date' => '2026-11-20']);
    $this->supervisor = makeAdmin();
});

function primaryOf(Cohort $cohort): ?string
{
    return app(PrimaryCoordinator::class)->idOf($cohort->fresh());
}

/** @return array<string, mixed> */
function cohortPayload(Cohort $cohort, string $status): array
{
    return [
        'program_id' => $cohort->program_id,
        'name' => 'دفعة الاختبار',
        'starts_at' => '2026-10-20',
        'ends_at' => '2026-11-20',
        'capacity' => 60,
        'registration_closes_at' => null,
        'pass_score' => 60,
        'min_attendance_rate' => 75,
        'requires_approval' => '0',
        'status' => $status,
    ];
}

function withdrawCoordinator(Cohort $cohort, User $coordinator): void
{
    Enrollment::query()
        ->where('cohort_id', $cohort->id)
        ->where('user_id', $coordinator->id)
        ->update(['status' => EnrollmentStatus::Withdrawn->value]);
}

it('D-124: المنسّق الوحيد أساسيّ بلا اختيار، ولا أساسيّ لدفعة بلا منسّق', function (): void {
    expect(primaryOf($this->cohort))->toBeNull();

    $only = makeCoordinator($this->cohort);

    expect(primaryOf($this->cohort))->toBe($only->id)
        ->and($this->cohort->fresh()->primary_coordinator_id)->toBeNull();
});

it('D-124: حين يُسند منسّق ثانٍ يبقى الأول أساسيًّا — يُكتب ولا يضيع', function (): void {
    $first = makeCoordinator($this->cohort);
    $second = makeUser('coordinator');

    $this->actingAs($this->supervisor)
        ->post(route('admin.cohorts.coordinators.attach', $this->cohort), ['coordinator_email' => $second->email])
        ->assertSessionHasNoErrors();

    expect($this->cohort->fresh()->primary_coordinator_id)->toBe($first->id)
        ->and(primaryOf($this->cohort))->toBe($first->id)
        ->and(app(PrimaryCoordinator::class)->coordinatorIds($this->cohort))->toContain($second->id);
});

it('D-124: المشرف العام يختار الأساسي من منسّقي الدفعة وحدهم، ويُسجَّل الاختيار', function (): void {
    makeCoordinator($this->cohort);
    $second = makeCoordinator($this->cohort);
    $outsider = makeUser('coordinator');

    expect(primaryOf($this->cohort))->toBeNull();

    $this->actingAs($this->supervisor)
        ->put(route('admin.cohorts.coordinators.primary', $this->cohort), ['coordinator_id' => $outsider->id])
        ->assertSessionHasErrors(['coordinator_id' => __('admin.cohorts.primary_not_coordinator')]);

    expect(primaryOf($this->cohort))->toBeNull();

    $this->actingAs($this->supervisor)
        ->put(route('admin.cohorts.coordinators.primary', $this->cohort), ['coordinator_id' => $second->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.cohorts.primary_set'));

    expect(primaryOf($this->cohort))->toBe($second->id)
        ->and(AuditLog::query()->where('action', 'cohort.primary_coordinator_set')->where('entity_id', $this->cohort->id)->exists())->toBeTrue();
});

it('D-124: 403 — لا يختار الأساسيَّ غيرُ المشرف العام، ولا المشرف أثناء معاينة', function (): void {
    $coordinator = makeCoordinator($this->cohort);
    makeCoordinator($this->cohort);

    $sysadmin = makeSystemAdmin();

    foreach ([$sysadmin, makeTrainer($this->cohort), $coordinator, makeParticipant($this->cohort)] as $actor) {
        $this->actingAs($actor)
            ->put(route('admin.cohorts.coordinators.primary', $this->cohort), ['coordinator_id' => $coordinator->id])
            ->assertForbidden();
    }

    // BR-33 — a preview of the supervisor's account reads, never writes.
    $this->actingAs($sysadmin)->post(route('admin.users.preview', $this->supervisor))->assertRedirect();

    $this->put(route('admin.cohorts.coordinators.primary', $this->cohort), ['coordinator_id' => $coordinator->id])
        ->assertForbidden();

    expect(primaryOf($this->cohort))->toBeNull()
        ->and(AuditLog::query()->where('action', 'cohort.primary_coordinator_set')->exists())->toBeFalse();
});

it('D-124: من غادر الدفعة لا يبقى أساسيًّا — يعود الأساسي إلى المنسّق الوحيد الباقي، ولا أحد إن بقي اثنان', function (): void {
    $chosen = makeCoordinator($this->cohort);
    $other = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $chosen->id]);

    withdrawCoordinator($this->cohort, $chosen);
    expect(primaryOf($this->cohort))->toBe($other->id);

    makeCoordinator($this->cohort);
    expect(primaryOf($this->cohort))->toBeNull();
});

it('D-124: لا يُفتح التسجيل لدفعة بلا منسّق أساسي، ويُغلق دائمًا', function (): void {
    $this->cohort->update(['status' => 'open']);

    // Already open (no settings row): asking for "open" again changes nothing
    // and is not refused; closing is always allowed.
    $this->actingAs($this->supervisor)
        ->put(route('admin.registrations.intake', $this->cohort), ['open' => '1'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->supervisor)
        ->put(route('admin.registrations.intake', $this->cohort), ['open' => '0'])
        ->assertSessionHasNoErrors();

    expect(LandingSetting::switchIsOn(LandingSetting::query()->forCohort($this->cohort)->first()))->toBeFalse();

    $this->actingAs($this->supervisor)
        ->put(route('admin.registrations.intake', $this->cohort), ['open' => '1'])
        ->assertSessionHasErrors(['open' => __('admin.registrations.intake.needs_primary_coordinator')]);

    expect(LandingSetting::switchIsOn(LandingSetting::query()->forCohort($this->cohort)->first()))->toBeFalse();

    makeCoordinator($this->cohort);

    $this->actingAs($this->supervisor)
        ->put(route('admin.registrations.intake', $this->cohort), ['open' => '1'])
        ->assertSessionHasNoErrors();

    expect(LandingSetting::switchIsOn(LandingSetting::query()->forCohort($this->cohort)->first()))->toBeTrue();

    $this->actingAs($this->supervisor)
        ->put(route('admin.registrations.intake', $this->cohort), ['open' => '0'])
        ->assertSessionHasNoErrors();
});

it('D-124: لا تنتقل الدفعة إلى «مفتوحة» ولا «جارية» بلا منسّق أساسي — وبقية التعديل لا تُمنع', function (): void {
    foreach (['open', 'running'] as $status) {
        $this->actingAs($this->supervisor)
            ->patch(route('admin.cohorts.update', $this->cohort), cohortPayload($this->cohort, $status))
            ->assertSessionHasErrors(['status' => __('admin.cohorts.needs_primary_coordinator')]);

        expect($this->cohort->fresh()->status->value)->toBe('upcoming');
    }

    // A cohort already running without one keeps its other edits: only the
    // move INTO the state is refused, and its tickets fall back meanwhile.
    $running = makeCohort(['status' => 'running', 'start_date' => '2026-10-20', 'end_date' => '2026-11-20']);

    $this->actingAs($this->supervisor)
        ->patch(route('admin.cohorts.update', $running), cohortPayload($running, 'running'))
        ->assertSessionHasNoErrors();

    makeCoordinator($this->cohort);

    $this->actingAs($this->supervisor)
        ->patch(route('admin.cohorts.update', $this->cohort), cohortPayload($this->cohort, 'open'))
        ->assertSessionHasNoErrors();

    expect($this->cohort->fresh()->status->value)->toBe('open');
});

it('D-124: الدفعة الجديدة تُنشأ «قادمة» — لا مفتوحةً ولا جاريةً قبل أن يُسند منسّقها', function (): void {
    $before = Cohort::query()->count();

    foreach (['open', 'running'] as $status) {
        $this->actingAs($this->supervisor)
            ->post(route('admin.cohorts.store'), cohortPayload($this->cohort, $status))
            ->assertSessionHasErrors(['status' => __('admin.cohorts.create_as_upcoming')]);
    }

    expect(Cohort::query()->count())->toBe($before);

    $this->actingAs($this->supervisor)
        ->post(route('admin.cohorts.store'), cohortPayload($this->cohort, 'upcoming'))
        ->assertSessionHasNoErrors();

    expect(Cohort::query()->count())->toBe($before + 1);
});

it('D-124: لا يُزال آخر منسّق، ولا الأساسي ما دام بعده اختيار — ومع منسّق واحد باقٍ يصير هو الأساسي', function (): void {
    $only = makeCoordinator($this->cohort);

    $this->actingAs($this->supervisor)
        ->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $only]))
        ->assertSessionHasErrors(['coordinator' => __('admin.cohorts.detach_last_coordinator')]);

    expect(app(PrimaryCoordinator::class)->coordinatorIds($this->cohort))->toBe([$only->id]);

    // Three coordinators, the first chosen: it may not leave while two remain.
    $second = makeCoordinator($this->cohort);
    $third = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $only->id]);

    $this->actingAs($this->supervisor)
        ->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $only]))
        ->assertSessionHasErrors(['coordinator' => __('admin.cohorts.detach_primary_first')]);

    // A coordinator who is not primary leaves freely.
    $this->actingAs($this->supervisor)
        ->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $third]))
        ->assertSessionHasNoErrors();

    // Two left: the primary may go, the other is then primary on their own.
    $this->actingAs($this->supervisor)
        ->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $only]))
        ->assertSessionHasNoErrors();

    expect($this->cohort->fresh()->primary_coordinator_id)->toBeNull()
        ->and(primaryOf($this->cohort))->toBe($second->id);
});

it('D-124: شاشة الدفعات تسمّي المنسّق الأساسي بشارته وتعرض الاختيار حيث يوجد، وتنبّه حين لا أساسي', function (): void {
    makeCoordinator($this->cohort);
    $second = makeCoordinator($this->cohort);

    // The badge itself, not the words: the "make primary" button carries them too.
    $badge = '/<span class="ui-badge ui-badge--brand[^"]*">\s*'.preg_quote((string) __('admin.cohorts.primary_badge'), '/').'\s*<\/span>/u';

    $before = (string) $this->actingAs($this->supervisor)
        ->get(route('admin.cohorts.index', ['trainers' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(__('admin.cohorts.needs_primary_note'))
        ->assertSee(route('admin.cohorts.coordinators.primary', $this->cohort), false)
        ->getContent();

    expect(preg_match_all($badge, $before))->toBe(0);

    $this->cohort->update(['primary_coordinator_id' => $second->id]);

    $after = (string) $this->actingAs($this->supervisor)
        ->get(route('admin.cohorts.index', ['trainers' => $this->cohort->id]))
        ->assertOk()
        ->assertDontSee(__('admin.cohorts.needs_primary_note'))
        ->getContent();

    expect(preg_match_all($badge, $after))->toBe(1);
});

it('D-124: لا يُختار أساسيًّا إلا منسّق فعّال في هذه الدفعة — لا منسّق دفعة أخرى ولا منسحب ولا متدرب ولا مدرّب ولا معرّف مشوّه', function (): void {
    makeCoordinator($this->cohort);
    makeCoordinator($this->cohort);

    $elsewhere = makeCoordinator(makeCohort());
    $withdrawn = makeCoordinator($this->cohort);
    withdrawCoordinator($this->cohort, $withdrawn);

    foreach ([$elsewhere->id, $withdrawn->id, makeParticipant($this->cohort)->id, makeTrainer($this->cohort)->id] as $id) {
        $this->actingAs($this->supervisor)
            ->put(route('admin.cohorts.coordinators.primary', $this->cohort), ['coordinator_id' => $id])
            ->assertSessionHasErrors(['coordinator_id' => __('admin.cohorts.primary_not_coordinator')]);
    }

    $this->actingAs($this->supervisor)
        ->put(route('admin.cohorts.coordinators.primary', $this->cohort), ['coordinator_id' => 'not-a-uuid'])
        ->assertSessionHasErrors('coordinator_id');

    expect(primaryOf($this->cohort))->toBeNull()
        ->and($this->cohort->fresh()->primary_coordinator_id)->toBeNull();
});

it('D-124: منسّق موقوف أو غُيّر دوره أو حُذف حسابه لا يُعدّ منسّقًا — لا أساسيًّا ولا في العدّ', function (Closure $disable): void {
    $gone = makeCoordinator($this->cohort);
    $active = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $gone->id]);

    $disable($gone);

    // The one who can act is primary on their own; the other is counted by
    // none of the rules — so removing the one who can act is removing the last.
    expect(primaryOf($this->cohort))->toBe($active->id)
        ->and(app(PrimaryCoordinator::class)->coordinatorIds($this->cohort))->toBe([$active->id]);

    $this->actingAs($this->supervisor)
        ->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $active]))
        ->assertSessionHasErrors(['coordinator' => __('admin.cohorts.detach_last_coordinator')]);

    if (! $gone->fresh()?->trashed()) {
        $this->actingAs($this->supervisor)
            ->put(route('admin.cohorts.coordinators.primary', $this->cohort), ['coordinator_id' => $gone->id])
            ->assertSessionHasErrors(['coordinator_id' => __('admin.cohorts.primary_not_coordinator')]);
    }
})->with([
    'موقوف' => [fn (User $user) => $user->forceFill(['status' => 'suspended'])->save()],
    'غُيّر دوره إلى متدرب' => [fn (User $user) => $user->forceFill(['role' => 'participant'])->save()],
    'حُذف حسابه' => [fn (User $user) => $user->delete()],
]);

it('D-124: حين يُسند منسّق جديد وعمود الأساسي يسمّي من غادر، يُكتب الأساسي الحالي — ويُسجَّل في التدقيق', function (): void {
    $left = makeCoordinator($this->cohort);
    $stays = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $left->id]);
    withdrawCoordinator($this->cohort, $left);

    expect(primaryOf($this->cohort))->toBe($stays->id);

    $newcomer = makeUser('coordinator');

    $this->actingAs($this->supervisor)
        ->post(route('admin.cohorts.coordinators.attach', $this->cohort), ['coordinator_email' => $newcomer->email])
        ->assertSessionHasNoErrors();

    $pin = AuditLog::query()->where('action', 'cohort.primary_coordinator_set')->where('entity_id', $this->cohort->id)->sole();

    expect($this->cohort->fresh()->primary_coordinator_id)->toBe($stays->id)
        ->and(primaryOf($this->cohort))->toBe($stays->id)
        ->and($pin->before['primary_coordinator_id'])->toBe($left->id)
        ->and($pin->after)->toMatchArray(['primary_coordinator_id' => $stays->id, 'reason' => 'kept_on_new_coordinator']);
});

it('D-124: إزالة المنسّق الأساسي حين يبقى واحد تُفرغ العمود وتُسجَّل في التدقيق', function (): void {
    $chosen = makeCoordinator($this->cohort);
    makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $chosen->id]);

    $this->actingAs($this->supervisor)
        ->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $chosen]))
        ->assertSessionHasNoErrors();

    $cleared = AuditLog::query()->where('action', 'cohort.primary_coordinator_set')->where('entity_id', $this->cohort->id)->sole();

    expect($this->cohort->fresh()->primary_coordinator_id)->toBeNull()
        ->and($cleared->after)->toMatchArray(['primary_coordinator_id' => null, 'reason' => 'coordinator_left']);
});

it('D-124: إسناد المنسّق مدرّبًا في دفعته يخضع لقاعدة الإزالة نفسها — لا آخر منسّق ولا الأساسي ما دام بعده اختيار', function (): void {
    // A general supervisor may hold both assignments; the row is one per
    // person and cohort, so the trainer form rewrites the coordinator's row.
    $only = makeAdmin();
    enroll($only, $this->cohort, 'coordinator');

    $this->actingAs($this->supervisor)
        ->post(route('admin.cohorts.trainers.attach', $this->cohort), ['trainer_email' => $only->email])
        ->assertSessionHasErrors(['trainer' => __('admin.cohorts.trainer_is_last_coordinator')]);

    expect(app(PrimaryCoordinator::class)->coordinatorIds($this->cohort))->toBe([$only->id]);

    $second = makeCoordinator($this->cohort);
    $third = makeAdmin();
    enroll($third, $this->cohort, 'coordinator');
    $this->cohort->update(['primary_coordinator_id' => $only->id]);

    $this->actingAs($this->supervisor)
        ->post(route('admin.cohorts.trainers.attach', $this->cohort), ['trainer_email' => $only->email])
        ->assertSessionHasErrors(['trainer' => __('admin.cohorts.trainer_is_primary_coordinator')]);

    // A coordinator who is not primary may become the cohort's trainer.
    $this->actingAs($this->supervisor)
        ->post(route('admin.cohorts.trainers.attach', $this->cohort), ['trainer_email' => $third->email])
        ->assertSessionHasNoErrors();

    expect(app(PrimaryCoordinator::class)->coordinatorIds($this->cohort))->toEqualCanonicalizing([$only->id, $second->id])
        ->and(primaryOf($this->cohort))->toBe($only->id);
});

it('D-124: رفض الحالة يظهر مرة واحدة — مرشّح القائمة لا يحمل اسم حقل الحالة', function (): void {
    $response = $this->actingAs($this->supervisor)
        ->from(route('admin.cohorts.index', ['edit' => $this->cohort->id]))
        ->patch(route('admin.cohorts.update', $this->cohort), cohortPayload($this->cohort, 'open'));

    $response->assertSessionHasErrors(['status' => __('admin.cohorts.needs_primary_coordinator')]);

    $page = (string) $this->actingAs($this->supervisor)
        ->withSession(['errors' => session('errors')])
        ->get(route('admin.cohorts.index', ['edit' => $this->cohort->id]))
        ->getContent();

    expect(substr_count($page, e((string) __('admin.cohorts.needs_primary_coordinator'))))->toBe(1);
});
