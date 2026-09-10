<?php

declare(strict_types=1);

/**
 * Opening the meeting link records attendance, and the trainer decides when the
 * link appears.
 *
 * WHY THIS SUITE EXISTS
 * `LiveController::join()` redirected to the meeting and recorded nothing. A
 * trainee who joined the session and sat through it was absent as far as the
 * platform was concerned unless they also remembered to press a second button
 * on a different screen — and attendance is what the certificate is computed
 * from (BR-20). The gap was silent: nothing errored, the rate was simply wrong.
 *
 * And the join window was `JOIN_OPENS_BEFORE_START_MINUTES = 15`, a PHP
 * constant. Every session everywhere opened its link a quarter of an hour early
 * and no trainer could say otherwise without a deploy.
 *
 * THE RULE THIS SUITE PROTECTS MOST: automatic attendance goes through the SAME
 * recorder as the manual button. BR-01's window, BR-03's late classification,
 * the enrolment guard and the audit row all live there, and a second path that
 * re-implemented any of them would drift from the first within a release.
 *
 * @see BR-01, BR-03, BR-06, BR-24 · PRD §9.10 · D-52
 */

use App\Models\Attendance;

/**
 * One fixed evening, so every instant below is stated relative to a start the
 * reader can see rather than to "now".
 */
function joinProbeSession(string $cohortId, array $attributes = []): App\Models\Session
{
    $start = riyadhAt('2026-09-20 19:00:00');

    return makeSessionAt($start, $start->addHours(2), $attributes + [
        'cohort_id' => $cohortId,
        'zoom_url' => 'https://example.test/meeting',
    ]);
}

function probeStart(): Carbon\CarbonImmutable
{
    return riyadhAt('2026-09-20 19:00:00');
}

beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
});

it('BR-24: فتح رابط المحاضرة يسجّل الحضور تلقائيًّا', function (): void {
    $session = joinProbeSession($this->cohort->id);

    // Inside the check-in window: the session has started.
    freezeAt(probeStart()->addMinutes(5));

    expect(Attendance::query()->where('user_id', $this->participant->getKey())->count())->toBe(0);

    $this->actingAs($this->participant)
        ->post(route('live.join', $session))
        ->assertRedirect('https://example.test/meeting');

    $record = Attendance::query()
        ->where('session_id', $session->getKey())
        ->where('user_id', $this->participant->getKey())
        ->first();

    expect($record)->not->toBeNull()
        // Recorded by the platform, not typed in by a human.
        ->and($record->getAttribute('is_manual'))->toBeFalse();
});

it('BR-03: الحضور التلقائي يُصنَّف متأخرًا بعد S+30د كما يفعل الزرّ اليدوي', function (): void {
    $session = joinProbeSession($this->cohort->id);

    freezeAt(probeStart()->addMinutes(31));

    $this->actingAs($this->participant)->post(route('live.join', $session));

    $record = Attendance::query()
        ->where('session_id', $session->getKey())
        ->where('user_id', $this->participant->getKey())
        ->first();

    // The classification is the recorder's, not this endpoint's — which is the
    // whole point of routing through it.
    //
    // Read through ->value: the column is cast to AttendanceStatus, so casting
    // the enum to a string is a fatal error, not a failing comparison. This
    // assertion had never run.
    expect($record)->not->toBeNull()
        ->and($record->getAttribute('status')->value)->toBe('late');
});

it('BR-06: فتح الرابط مرتين لا يُنشئ سجلَّي حضور', function (): void {
    $session = joinProbeSession($this->cohort->id);

    freezeAt(probeStart()->addMinutes(5));

    $this->actingAs($this->participant)->post(route('live.join', $session));
    $this->actingAs($this->participant)->post(route('live.join', $session))
        // The second open must still reach the meeting: attendance rules decide
        // what the record says, not who may attend.
        ->assertRedirect('https://example.test/meeting');

    expect(Attendance::query()
        ->where('session_id', $session->getKey())
        ->where('user_id', $this->participant->getKey())
        ->count())->toBe(1);
});

it('D-52: المدرّب يحدّد متى يظهر الرابط، والفارغ يعني الافتراضي', function (): void {
    $early = joinProbeSession($this->cohort->id, ['join_opens_minutes' => 45]);

    // Forty minutes before the start: outside the platform's default quarter of
    // an hour, inside this trainer's forty-five.
    freezeAt(probeStart()->subMinutes(40));

    $this->actingAs($this->participant)
        ->post(route('live.join', $early))
        ->assertRedirect('https://example.test/meeting');

    $default = joinProbeSession($this->cohort->id, ['join_opens_minutes' => null]);

    freezeAt(probeStart()->subMinutes(40));

    // The same instant, with no trainer preference, is still too early.
    $this->actingAs($this->participant)
        ->post(route('live.join', $default))
        ->assertRedirect();

    expect(Attendance::query()->where('session_id', $default->getKey())->count())->toBe(0);
});

it('D-52: صفر دقيقة تعني صفرًا فعلًا، لا "غير محدَّد"', function (): void {
    // The distinction the cast comment exists for: null is "no preference",
    // zero is "not a minute early". Treating them alike would silently reopen
    // every link fifteen minutes ahead of a trainer who asked for none.
    $session = joinProbeSession($this->cohort->id, ['join_opens_minutes' => 0]);

    freezeAt(probeStart()->subMinutes(5));

    $this->actingAs($this->participant)->post(route('live.join', $session));

    expect(Attendance::query()->where('session_id', $session->getKey())->count())->toBe(0);
});
