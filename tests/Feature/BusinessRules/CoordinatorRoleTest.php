<?php

declare(strict_types=1);

/**
 * The coordinator role (D-105): responsible for attendance only, on the
 * cohorts they were explicitly assigned to — never session, resource or
 * cohort management, and never a cohort nobody attached them to.
 *
 * @see D-105 · CONSTITUTION Art. 22
 */

use App\Enums\SessionDeliveryMode;
use App\Models\Enrollment;
use App\Models\Session;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->otherCohort = makeCohort();
    $this->admin = makeAdmin();
    $this->coordinator = makeCoordinator($this->cohort);

    $start = riyadhAt('2026-10-12 18:00:00');
    $this->session = sessionInCohort($this->cohort, $start, $start->addHours(2));
});

it('D-105: المنسّق يصل تحضير دفعته المسندة إليه', function (): void {
    $this->actingAs($this->coordinator)
        ->get(route('trainer.attendance', ['cohort' => $this->cohort->id]))
        ->assertOk();
});

it('D-105: 403 — المنسّق لا يصل دفعة لم يُسنَد إليها', function (): void {
    $this->actingAs($this->coordinator)
        ->get(route('trainer.attendance', ['cohort' => $this->otherCohort->id]))
        ->assertForbidden();
});

it('D-105: 403 — المنسّق لا ينشئ جلسة رغم وصوله لتحضيرها', function (): void {
    $this->actingAs($this->coordinator)
        ->post(route('trainer.sessions.store', ['cohort' => $this->cohort->id]), [
            'topic' => 'CANARY-COORDINATOR-SESSION',
            'type' => 'training',
            'date' => '2026-10-20',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'delivery_mode' => 'online',
        ])
        ->assertForbidden();

    expect(Session::query()->where('topic', 'CANARY-COORDINATOR-SESSION')->exists())->toBeFalse();
});

it('D-105: 403 — المنسّق لا يؤرشف موردًا رغم وصوله للدفعة', function (): void {
    $resource = App\Models\Resource::factory()->create([
        'cohort_id' => $this->cohort->id,
        'type' => 'link',
        'external_url' => 'https://example.test/reading',
    ]);

    $this->actingAs($this->coordinator)
        ->delete(route('trainer.resources.archive', ['cohort' => $this->cohort->id, 'resource' => $resource->id]))
        ->assertForbidden();
});

it('D-105: المشرف ينشئ جلسة حضورية بموقع، ورابط الاجتماع غير مطلوب', function (): void {
    $this->actingAs($this->admin)
        ->post(route('trainer.sessions.store', ['cohort' => $this->cohort->id]), [
            'topic' => 'CANARY-IN-PERSON',
            'type' => 'training',
            'date' => '2026-10-21',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'delivery_mode' => 'in_person',
            'location_name' => 'مقر مركز أثر',
            'location_map_url' => 'https://maps.example.test/athar',
            'room_name' => 'A101',
        ])
        ->assertSessionHasNoErrors();

    $session = Session::query()->where('topic', 'CANARY-IN-PERSON')->sole();

    expect($session->delivery_mode)->toBe(SessionDeliveryMode::InPerson)
        ->and($session->location_name)->toBe('مقر مركز أثر')
        ->and($session->room_name)->toBe('A101');
});

it('D-105: المشرف يسند منسّقًا للدفعة عبر بريده، فيصل تحضيرها فورًا', function (): void {
    $newCoordinator = makeCoordinator();

    $this->actingAs($this->admin)
        ->post(route('admin.cohorts.coordinators.attach', $this->cohort), ['email' => $newCoordinator->email])
        ->assertSessionHasNoErrors();

    expect(
        Enrollment::query()
            ->where('cohort_id', $this->cohort->id)
            ->where('user_id', $newCoordinator->id)
            ->where('role_in_cohort', 'coordinator')
            ->where('status', 'active')
            ->exists()
    )->toBeTrue();

    $this->actingAs($newCoordinator)
        ->get(route('trainer.attendance', ['cohort' => $this->cohort->id]))
        ->assertOk();
});

it('D-105: إزالة إسناد المنسّق تقطع وصوله فورًا', function (): void {
    $this->actingAs($this->admin)
        ->delete(route('admin.cohorts.coordinators.detach', [$this->cohort, $this->coordinator]))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->coordinator)
        ->get(route('trainer.attendance', ['cohort' => $this->cohort->id]))
        ->assertForbidden();
});
