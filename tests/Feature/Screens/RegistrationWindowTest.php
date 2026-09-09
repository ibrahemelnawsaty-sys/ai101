<?php

declare(strict_types=1);

/**
 * The landing page always gives a visitor something to do.
 *
 * WHY THIS SUITE EXISTS
 * Registration closing removed two buttons and put nothing in their place. The
 * four guards in the template were `@if` with no `@else`, so a visitor arriving
 * after the deadline found a page with one secondary link on it — while the site
 * header still said "سجّل الآن", and /register turned them away with copy
 * pointing back at the landing page to leave their address. There was no form on
 * the landing page: `grep -c "<form"` returned zero. The loop was closed.
 *
 * Everything needed already existed — the route, the controller, the audit
 * record and all five sentences. Only the form was missing.
 *
 * The countdown is BR-07 territory: the deadline and the reference instant are
 * both the SERVER's, so the boundaries are asserted at ±1 second around the
 * closing instant rather than trusted to a device clock.
 *
 * @see BR-07, BR-30 · PRD §9.1.2 · CONSTITUTION.md Article 11, Article 17
 */

use App\Enums\CohortStatus;
use App\Models\LandingSetting;

/** A cohort whose registration closes at a known instant. */
function cohortClosingAt(string $riyadh): App\Models\Cohort
{
    return makeCohort([
        'status' => CohortStatus::Open->value,
        'capacity' => 30,
        'seats_taken' => 0,
        'registration_closes_at' => riyadhAt($riyadh),
    ]);
}

it('BR-07: نافذة التسجيل مفتوحة قبل لحظة الإغلاق بثانية', function (): void {
    cohortClosingAt('2026-10-01 20:00:00');
    freezeAt(riyadhAt('2026-10-01 19:59:59'));

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('data-registration="open"')
        ->and($body)->toContain('id="heroCountdown"')
        ->and($body)->toContain(route('register'))
        ->and($body)->not->toContain(__('landing.hero.registration_closed_title'));
});

it('BR-07: عند لحظة الإغلاق بالضبط تُغلق النافذة', function (): void {
    // The boundary itself is closed, not open: `lessThan` is strict.
    cohortClosingAt('2026-10-01 20:00:00');
    freezeAt(riyadhAt('2026-10-01 20:00:00'));

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('data-registration="closed"')
        ->and($body)->not->toContain('id="heroCountdown"');
});

it('BR-07: بعد لحظة الإغلاق بثانية تُعرض حالة الإغلاق ونموذج الانتظار', function (): void {
    cohortClosingAt('2026-10-01 20:00:00');
    freezeAt(riyadhAt('2026-10-01 20:00:01'));

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('data-registration="closed"')
        ->and($body)->toContain(__('landing.hero.registration_closed_title'))
        // The point of the whole state: an action, not a dead end.
        ->and($body)->toContain(route('waitlist.store'))
        ->and($body)->toContain('name="email"');
});

it('BR-07: العدّاد يحمل لحظة الخادم لا لحظة المتصفح', function (): void {
    cohortClosingAt('2026-10-01 20:00:00');
    freezeAt(riyadhAt('2026-10-01 19:00:00'));

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect(preg_match('/id="heroCountdown"[^>]*data-until="([^"]*)"[^>]*data-now="([^"]*)"/s', $body, $m))->toBe(1);

    // Both instants are the server's, and an hour apart exactly.
    expect(strtotime($m[2]))->toBe(strtotime('2026-10-01 19:00:00 +0300'))
        ->and(strtotime($m[1]))->toBe(strtotime('2026-10-01 20:00:00 +0300'))
        ->and(strtotime($m[1]) - strtotime($m[2]))->toBe(3600);
});

it('PRD §9.1.2: نفاد المقاعد يغلق التسجيل ويعرض النموذج نفسه', function (): void {
    // A different route to the same state: the deadline has not passed, the
    // seats have run out. The visitor must still have somewhere to go.
    $cohort = cohortClosingAt('2026-12-01 20:00:00');
    $cohort->forceFill(['seats_taken' => 30])->save();

    freezeAt(riyadhAt('2026-10-01 19:00:00'));

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('data-registration="closed"')
        ->and($body)->toContain(route('waitlist.store'));
});

it('PRD §9.1.2: إيقاف التسجيل من لوحة الإدارة يعرض النموذج', function (): void {
    $cohort = cohortClosingAt('2026-12-01 20:00:00');

    LandingSetting::factory()->create([
        'cohort_id' => $cohort->id,
        'is_registration_open' => false,
    ]);

    freezeAt(riyadhAt('2026-10-01 19:00:00'));

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('data-registration="closed"')
        ->and($body)->toContain(route('waitlist.store'));
});

it('BR-30: نموذج الانتظار يردّ الجواب نفسه ولا يكشف من سجّل', function (): void {
    cohortClosingAt('2026-10-01 20:00:00');
    freezeAt(riyadhAt('2026-10-01 20:00:01'));

    $known = makeParticipant();

    $first = $this->post(route('waitlist.store'), ['email' => 'stranger@example.com'])
        ->assertRedirect(route('home'));

    $second = $this->post(route('waitlist.store'), ['email' => $known->email])
        ->assertRedirect(route('home'));

    // Identical replies: the form cannot be used to ask who is registered.
    expect($first->getSession()->get('status'))
        ->toBe($second->getSession()->get('status'))
        ->and($first->getSession()->get('status'))
        ->toBe(__('landing.waitlist.acknowledged'));
});

it('المادة 17: الصفحة بعد ترك البريد تعرض التأكيد لا النموذج مرة أخرى', function (): void {
    cohortClosingAt('2026-10-01 20:00:00');
    freezeAt(riyadhAt('2026-10-01 20:00:01'));

    $body = $this->followingRedirects()
        ->post(route('waitlist.store'), ['email' => 'stranger@example.com'])
        ->assertOk()
        ->getContent();

    expect($body)->toContain(__('landing.waitlist.acknowledged'))
        ->and($body)->not->toContain('name="email"');
});
