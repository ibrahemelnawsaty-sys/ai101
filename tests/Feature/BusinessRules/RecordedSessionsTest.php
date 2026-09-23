<?php

declare(strict_types=1);

/**
 * D-107 — recorded sessions: a coordinator's narrow write on `recording_url`
 * for a finished session, and the safe inline embedding a participant sees on
 * the live tab. The url is never rendered anywhere before the guarded
 * `live.recording` route, and only ever as the `src` of a server-built
 * `<iframe>`, never as markup a user supplied (CONSTITUTION Article 24).
 *
 * @see D-105, D-107 · CONSTITUTION Art. 22, Art. 24
 */

uses()->group('authz');

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->coordinator = makeCoordinator($this->cohort);
    $this->participant = makeParticipant($this->cohort);

    $this->foreignCohort = makeCohort();
    $this->outsiderCoordinator = makeCoordinator($this->foreignCohort);

    $this->session = sessionInCohort(
        $this->cohort,
        riyadhAt('2026-10-10 18:00:00'),
        riyadhAt('2026-10-10 20:00:00'),
        ['status' => 'completed', 'topic' => 'جلسة اختبار مسجَّلة'],
    );
});

it('D-107: المنسّق يرفع رابط تسجيل لجلسة منتهية دون صلاحية تعديل بقية بياناتها', function (): void {
    $originalTopic = $this->session->topic;

    $response = $this->actingAs($this->coordinator)->patch(
        route('trainer.sessions.recording', $this->session),
        [
            'recording_url' => 'https://us02web.zoom.us/rec/share/abc123',
            // A crafted extra field. UpdateSessionRecordingRequest defines no
            // rule for it, so it can never reach columns() — proving the
            // narrow endpoint really is narrow, not merely undocumented.
            'topic' => 'CANARY-SHOULD-NOT-BE-WRITTEN',
        ],
    );

    assertAccepted($response);

    $this->session->refresh();
    expect($this->session->recording_url)->toBe('https://us02web.zoom.us/rec/share/abc123')
        ->and($this->session->topic)->toBe($originalTopic);
});

it('D-107: 403 — منسّق من دفعة أخرى لا يرفع رابط تسجيل لجلسة ليست له', function (): void {
    $response = $this->actingAs($this->outsiderCoordinator)->patch(
        route('trainer.sessions.recording', $this->session),
        ['recording_url' => 'https://zoom.us/rec/share/should-not-work'],
    );

    expect($response->status())->toBe(403);

    $this->session->refresh();
    expect($this->session->recording_url)->toBeNull();
});

it('D-107: 403 — المنسّق لا يعدّل أي حقل آخر عبر مسار تعديل الجلسة الكامل', function (): void {
    $response = $this->actingAs($this->coordinator)->patch(
        route('trainer.sessions.update', $this->session),
        ['topic' => 'CANARY', 'type' => 'training', 'date' => '2026-10-10', 'start_time' => '18:00', 'end_time' => '20:00', 'delivery_mode' => 'online'],
    );

    expect($response->status())->toBe(403);
});

it('D-107: رابط تسجيل من نطاق ليس زوم يُرفض ولا يُحفظ', function (): void {
    $response = $this->actingAs($this->coordinator)->patch(
        route('trainer.sessions.recording', $this->session),
        ['recording_url' => 'https://evil.com/rec/123'],
    );

    assertRefused($response);
    $response->assertSessionHasErrors('recording_url');

    $this->session->refresh();
    expect($this->session->recording_url)->toBeNull();
});

it('D-107: كود دمج iframe من زوم يُقبل ويُخزَّن كرابط نظيف لا HTML', function (): void {
    $embed = '<iframe src="https://us05web.zoom.us/rec/play/xyz789" width="640" height="360" allowfullscreen></iframe>';

    $response = $this->actingAs($this->coordinator)->patch(
        route('trainer.sessions.recording', $this->session),
        ['recording_url' => $embed],
    );

    assertAccepted($response);

    $this->session->refresh();
    expect($this->session->recording_url)->toBe('https://us05web.zoom.us/rec/play/xyz789')
        ->and($this->session->recording_url)->not->toContain('<iframe');
});

it('D-107: المتدرب يرى قسم المحاضرات المسجلة منفصلًا داخل تبويب المحاضرات المباشرة', function (): void {
    $this->session->update(['recording_url' => 'https://zoom.us/rec/share/abc123']);

    $upcoming = sessionInCohort(
        $this->cohort,
        riyadhAt('2026-10-20 18:00:00'),
        riyadhAt('2026-10-20 20:00:00'),
        ['status' => 'scheduled', 'topic' => 'جلسة قادمة'],
    );

    $response = $this->actingAs($this->participant)->get(route('live'));

    $response->assertOk();
    $response->assertViewHas('recordings', function ($recordings) {
        return $recordings->getCollection()->pluck('id')->contains((string) $this->session->id);
    });
    $response->assertViewHas('upcoming', function ($upcomingList) use ($upcoming) {
        return $upcomingList->pluck('id')->contains((string) $upcoming->id);
    });
    $response->assertSee('جلسة اختبار مسجَّلة');

    // BR-22: the raw host is never in the listing page, only behind the guard.
    $response->assertDontSee('zoom.us', false);
});

it('D-107: المتدرب يشاهد التسجيل داخل إطار مضمَّن ببرمجة آمنة، لا تحويلة خارجية', function (): void {
    $this->session->update(['recording_url' => 'https://us02web.zoom.us/rec/share/abc123']);

    $response = $this->actingAs($this->participant)->get(route('live.recording', $this->session));

    $response->assertOk();
    $response->assertSee('<iframe src="https://us02web.zoom.us/rec/share/abc123"', false);
});

it('D-107: 404 — لا يوجد تسجيل بعد لهذه الجلسة المنتهية', function (): void {
    $response = $this->actingAs($this->participant)->get(route('live.recording', $this->session));

    $response->assertNotFound();
});

it('D-107: 403 — متدرب من دفعة أخرى لا يشاهد تسجيل جلسة ليست دفعته', function (): void {
    $this->session->update(['recording_url' => 'https://zoom.us/rec/share/abc123']);

    $foreignParticipant = makeParticipant($this->foreignCohort);

    $response = $this->actingAs($foreignParticipant)->get(route('live.recording', $this->session));

    expect($response->status())->toBe(403);
});

it('D-107: جلسة غير منتهية لا تُعرض ضمن قائمة التسجيلات القابلة للتحرير في شاشة الحضور', function (): void {
    $upcoming = sessionInCohort(
        $this->cohort,
        riyadhAt('2026-10-20 18:00:00'),
        riyadhAt('2026-10-20 20:00:00'),
        ['status' => 'scheduled'],
    );

    $response = $this->actingAs($this->coordinator)->get(route('trainer.attendance'));

    $response->assertOk();
    $response->assertViewHas('recordingSessions', function ($rows) use ($upcoming) {
        return ! $rows->pluck('id')->contains((string) $upcoming->id)
            && $rows->pluck('id')->contains((string) $this->session->id);
    });
});
