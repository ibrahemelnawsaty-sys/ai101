<?php

declare(strict_types=1);

/**
 * Deciding an enrolment request — once, and only while it is still pending.
 *
 * WHY THIS SUITE EXISTS
 * approve() never read the request's status (D-69). A second click, a
 * resubmit, or two administrators at once each took another seat and sent
 * another acceptance; approving a withdrawn or completed row re-activated it;
 * and a cohort found full under the lock returned from the closure only, so
 * the acceptance letter went out for a seat nobody got. reject() overwrote any
 * status — an accepted participant could be sent a refusal. None of it had a
 * behaviour test; the only coverage was an authorisation row whose admin
 * approved an ACTIVE fixture — itself a double approval.
 *
 * @see PRD §9.2.3 · BR-27 · D-51, D-69
 */

use App\Enums\EnrollmentStatus;
use App\Events\EnrollmentApproved;
use App\Events\EnrollmentRejected;
use App\Models\AuditLog;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Notification;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));
    Event::fake([EnrollmentApproved::class, EnrollmentRejected::class]);

    $this->admin = makeAdmin();
    $this->cohort = makeCohort(['capacity' => 10, 'seats_taken' => 3]);
});

function requestFor(Cohort $cohort, string $state = 'pending'): Enrollment
{
    $factory = $state === 'active' ? Enrollment::factory() : Enrollment::factory()->{$state}();

    return $factory->create([
        'cohort_id' => $cohort->id,
        'user_id' => makeUser('participant')->id,
    ]);
}

function seatsOf(Cohort $cohort): int
{
    return (int) $cohort->fresh()?->seats_taken;
}

it('D-69: اعتماد طلب معلّق يفعّله ويأخذ مقعدًا واحدًا ويكتب سطر تدقيق من «معلّق» ويرسل إشعارًا ورسالة', function (): void {
    $request = requestFor($this->cohort);

    $this->actingAs($this->admin)
        ->from(route('admin.registrations.index', ['review' => $request->id]))
        ->put(route('admin.registrations.approve', $request))
        ->assertSessionHas('status', __('admin.registrations.approved'));

    expect($request->fresh()?->status)->toBe(EnrollmentStatus::Active)
        ->and(seatsOf($this->cohort))->toBe(4)
        ->and(AuditLog::query()->where('action', 'registration.approved')->sole()->before)
        ->toMatchArray(['status' => 'pending'])
        ->and(Notification::query()->where('user_id', $request->user_id)->where('type', 'enrollment_approved')->count())
        ->toBe(1);

    Event::assertDispatchedTimes(EnrollmentApproved::class, 1);
});

it('BR-27: اعتماد الطلب نفسه مرتين يأخذ مقعدًا واحدًا ويكتب سطر تدقيق واحدًا ويرسل رسالة واحدة', function (): void {
    $request = requestFor($this->cohort);

    $this->actingAs($this->admin)->put(route('admin.registrations.approve', $request));
    $this->actingAs($this->admin)->put(route('admin.registrations.approve', $request))
        ->assertSessionHas('warning', __('admin.registrations.already_decided'));

    expect(seatsOf($this->cohort))->toBe(4)
        ->and(AuditLog::query()->where('action', 'registration.approved')->count())->toBe(1);

    Event::assertDispatchedTimes(EnrollmentApproved::class, 1);
});

it('D-69: اعتماد طلب بُتّ فيه لا يغيّر شيئًا ولا يرسل رسالة', function (string $state): void {
    $request = requestFor($this->cohort, $state);
    $before = $request->fresh();

    $this->actingAs($this->admin)
        ->put(route('admin.registrations.approve', $request))
        ->assertSessionHas('warning', __('admin.registrations.already_decided'));

    $after = $request->fresh();

    expect($after?->status)->toBe($before?->status)
        ->and((string) $after?->enrolled_at)->toBe((string) $before?->enrolled_at)
        ->and(seatsOf($this->cohort))->toBe(3)
        ->and(AuditLog::query()->where('action', 'registration.approved')->count())->toBe(0);

    Event::assertNotDispatched(EnrollmentApproved::class);
})->with(['active', 'completed', 'withdrawn']);

it('D-69: الاعتماد في دفعة ممتلئة يُبقي الطلب معلّقًا ولا يأخذ مقعدًا ولا يرسل رسالة قبول', function (): void {
    $full = makeCohort(['capacity' => 1, 'seats_taken' => 1]);
    $request = requestFor($full);

    $this->actingAs($this->admin)
        ->put(route('admin.registrations.approve', $request))
        ->assertSessionHas('error', __('admin.registrations.no_free_seat'));

    expect($request->fresh()?->status)->toBe(EnrollmentStatus::Pending)
        ->and(seatsOf($full))->toBe(1)
        ->and(AuditLog::query()->where('action', 'registration.approved')->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);

    Event::assertNotDispatched(EnrollmentApproved::class);
});

it('D-69: رفض طلب معلّق يسحبه ويسجّل السبب ويرسل رسالة واحدة', function (): void {
    $request = requestFor($this->cohort);

    $this->actingAs($this->admin)
        ->put(route('admin.registrations.reject', $request), ['reject_reason' => 'The cohort is for employees only.'])
        ->assertSessionHas('status', __('admin.registrations.rejected'));

    expect($request->fresh()?->status)->toBe(EnrollmentStatus::Withdrawn)
        ->and(AuditLog::query()->where('action', 'registration.rejected')->sole()->after)
        ->toMatchArray(['reason' => 'The cohort is for employees only.']);

    Event::assertDispatchedTimes(EnrollmentRejected::class, 1);
});

it('D-69: رفض طلب بُتّ فيه يُبقي حالته ولا يرسل رسالة رفض', function (string $state): void {
    $request = requestFor($this->cohort, $state);
    $before = $request->fresh()?->status;

    $this->actingAs($this->admin)
        ->put(route('admin.registrations.reject', $request), ['reject_reason' => 'The cohort is for employees only.'])
        ->assertSessionHas('warning', __('admin.registrations.already_decided'));

    expect($request->fresh()?->status)->toBe($before)
        ->and(AuditLog::query()->where('action', 'registration.rejected')->count())->toBe(0);

    Event::assertNotDispatched(EnrollmentRejected::class);
})->with(['active', 'completed', 'withdrawn']);

it('D-69: لوحة المراجعة تُفتح لطلب معلّق ولا تُفتح لطلب بُتّ فيه', function (): void {
    $pending = requestFor($this->cohort);
    $active = requestFor($this->cohort, 'active');

    $this->actingAs($this->admin)
        ->get(route('admin.registrations.index', ['review' => $pending->id]))
        ->assertOk()
        ->assertSee(route('admin.registrations.approve', $pending), false);

    $this->actingAs($this->admin)
        ->get(route('admin.registrations.index', ['review' => $active->id]))
        ->assertOk()
        ->assertDontSee(route('admin.registrations.approve', $active), false);
});
