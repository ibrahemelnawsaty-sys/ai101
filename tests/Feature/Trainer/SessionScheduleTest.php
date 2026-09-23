<?php

declare(strict_types=1);

/**
 * D-109 amends D-105: the schedule — creating, editing, cancelling a session
 * and holding its meeting link — moved from trainer+admin to admin+coordinator
 * only, so a trainer's own edit could never duplicate the coordinator's.
 * `trainer.sessions` stays reachable by the trainer, read-only.
 *
 * @see D-105, D-109 · PRD §9.8, §9.10 · CONSTITUTION Art. 5, Art. 22
 */

use App\Models\Session;

beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->coordinator = makeCoordinator($this->cohort);
    $this->admin = makeAdmin();

    $start = riyadhAt('2026-10-12 18:00:00');
    $this->session = sessionInCohort($this->cohort, $start, $start->addHours(3), [
        'trainer_id' => $this->trainer->id,
        'delivery_mode' => 'online',
        'platform' => 'zoom',
        'meeting_url' => 'https://zoom.us/j/123',
    ]);
});

it('D-109: المدرب يرى جدول جلساته للاطّلاع فقط دون أزرار الإدارة', function (): void {
    $response = $this->actingAs($this->trainer)
        ->get(route('trainer.sessions', ['cohort' => $this->cohort->id]));

    $response->assertOk()
        ->assertSee(__('trainer.sessions.readonly_title'))
        ->assertDontSee(__('trainer.sessions.create'))
        ->assertDontSee(__('trainer.sessions.cancel'));
});

it('D-109: المنسّق يرى نموذج الإدارة الكامل ويستطيع إنشاء جلسة', function (): void {
    $this->actingAs($this->coordinator)
        ->get(route('trainer.sessions', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(__('trainer.sessions.create'))
        ->assertDontSee(__('trainer.sessions.readonly_title'));

    $this->actingAs($this->coordinator)
        ->post(route('trainer.sessions.store', ['cohort' => $this->cohort->id]), [
            'topic' => 'CANARY-COORDINATOR-SESSION',
            'type' => 'training',
            'date' => '2026-10-20',
            'start_time' => '18:00',
            'end_time' => '20:00',
            'delivery_mode' => 'online',
            'platform' => 'google_meet',
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $created = Session::query()->where('topic', 'CANARY-COORDINATOR-SESSION')->sole();

    expect($created->platform->value)->toBe('google_meet')
        ->and($created->meeting_url)->toBe('https://meet.google.com/abc-defg-hij');
});

it('D-109: 403 — المدرب لا ينشئ جلسة ولا يعدّلها ولا يلغيها', function (): void {
    $this->actingAs($this->trainer)
        ->post(route('trainer.sessions.store', ['cohort' => $this->cohort->id]), [
            'topic' => 'CANARY-TRAINER-BLOCKED',
            'type' => 'training',
            'date' => '2026-10-20',
            'start_time' => '18:00',
            'end_time' => '20:00',
            'delivery_mode' => 'online',
        ])
        ->assertForbidden();

    expect(Session::query()->where('topic', 'CANARY-TRAINER-BLOCKED')->exists())->toBeFalse();

    $this->actingAs($this->trainer)
        ->patch(route('trainer.sessions.update', ['cohort' => $this->cohort->id, 'session' => $this->session->id]), [
            'topic' => 'CANARY-SHOULD-NOT-APPLY',
            'type' => 'training',
            'date' => '2026-10-12',
            'start_time' => '18:00',
            'end_time' => '21:00',
            'delivery_mode' => 'online',
        ])
        ->assertForbidden();

    $this->actingAs($this->trainer)
        ->post(route('trainer.sessions.cancel', ['cohort' => $this->cohort->id, 'session' => $this->session->id]), [
            'cancel_reason' => 'CANARY-REASON-1234567890',
        ])
        ->assertForbidden();

    expect($this->session->refresh()->topic)->not->toBe('CANARY-SHOULD-NOT-APPLY')
        ->and($this->session->status->value)->toBe('scheduled');
});

it('D-109: شارة منصة البث تظهر بشعارها للمدرب والمنسّق معًا', function (): void {
    $this->actingAs($this->trainer)
        ->get(route('trainer.sessions', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(__('enums.session_platform.zoom'));

    $this->actingAs($this->coordinator)
        ->get(route('trainer.sessions', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(__('enums.session_platform.zoom'));
});
