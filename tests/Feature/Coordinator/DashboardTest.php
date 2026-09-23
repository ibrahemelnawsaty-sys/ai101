<?php

declare(strict_types=1);

/**
 * PR-5 دفعة 2: the coordinator's own information dashboard — the coordinator
 * role's first screen under its own coordinator.* prefix, since D-109/D-106
 * gave it real responsibilities (the schedule's own missing details, the
 * excuse-request queue) with nowhere before this to see them at a glance.
 *
 * `sessionsNeedingAttention` reuses SessionRow's own `hasMeetingUrl` /
 * `isInPerson` / `hasLocation` flags rather than re-deriving the rule a
 * session is "ready" a second time (Art. 6) — this suite proves the filter
 * over those flags, not a new definition of readiness.
 *
 * @see D-105, D-106, D-109 · CONSTITUTION Art. 6, Art. 17
 */

use App\Enums\AttendanceExceptionType;
use App\Enums\SessionDeliveryMode;
use App\Services\Attendance\AttendanceExceptionRequester;

it('لوحة معلومات المنسّق تعرض عدد متدربي دفعته فقط', function (): void {
    $cohort = makeCohort();
    $coordinator = makeCoordinator($cohort);
    makeParticipant($cohort);
    makeParticipant($cohort);
    makeParticipant($cohort);

    $otherCohort = makeCohort();
    makeParticipant($otherCohort);

    $this->actingAs($coordinator)
        ->get(route('coordinator.dashboard', ['cohort' => $cohort->id]))
        ->assertOk()
        ->assertViewHas('participantCount', 3);
});

it('D-109: جلسة عن بُعد بلا رابط وجلسة حضورية بلا موقع تظهران في قائمة الانتباه دون الجلسة المكتملة', function (): void {
    $cohort = makeCohort();
    $coordinator = makeCoordinator($cohort);

    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    sessionInCohort($cohort, riyadhAt('2026-10-02 18:00:00'), riyadhAt('2026-10-02 20:00:00'), [
        'topic' => 'CANARY-ONLINE-NO-LINK',
    ]);
    sessionInCohort($cohort, riyadhAt('2026-10-03 18:00:00'), riyadhAt('2026-10-03 20:00:00'), [
        'topic' => 'CANARY-IN-PERSON-NO-LOCATION',
        'delivery_mode' => SessionDeliveryMode::InPerson->value,
    ]);
    sessionInCohort($cohort, riyadhAt('2026-10-04 18:00:00'), riyadhAt('2026-10-04 20:00:00'), [
        'topic' => 'CANARY-FULLY-SET-UP',
        'meeting_url' => 'https://zoom.us/j/123456789',
    ]);

    $response = $this->actingAs($coordinator)->get(route('coordinator.dashboard', ['cohort' => $cohort->id]));

    $response->assertOk()
        ->assertSee('CANARY-ONLINE-NO-LINK')
        ->assertSee('CANARY-IN-PERSON-NO-LOCATION')
        ->assertDontSee('CANARY-FULLY-SET-UP');
});

it('D-106: لوحة معلومات المنسّق تعرض عدد طلبات الأعذار المعلّقة لدفعته', function (): void {
    $cohort = makeCohort();
    $coordinator = makeCoordinator($cohort);
    $participant = makeParticipant($cohort);

    $start = riyadhAt('2026-10-01 18:00:00');
    $session = sessionInCohort($cohort, $start, $start->addHours(2));
    $attendance = makeAttendance($session, $participant, 'absent');

    app(AttendanceExceptionRequester::class)->request(
        $participant,
        $attendance,
        AttendanceExceptionType::Absence,
        'سبب حقيقي بعشرة أحرف على الأقل.',
    );

    $this->actingAs($coordinator)
        ->get(route('coordinator.dashboard', ['cohort' => $cohort->id]))
        ->assertOk()
        ->assertViewHas('pendingExceptionsTotal', 1);
});

it('لوحة معلومات المنسّق لا تنهار لمنسّق بلا دفعة مسندة', function (): void {
    $coordinator = makeCoordinator();

    $this->actingAs($coordinator)
        ->get(route('coordinator.dashboard'))
        ->assertOk()
        ->assertSee(__('coordinator.dashboard.next_session_empty_title'));
});
