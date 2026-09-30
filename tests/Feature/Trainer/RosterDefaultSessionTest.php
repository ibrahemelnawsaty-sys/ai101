<?php

declare(strict_types=1);

/**
 * Phase 4 (4-أ) — the attendance roster opens on the session the trainer needs.
 *
 * It opened on the OLDEST session of the cohort, so on the day of the fourth week
 * the trainer met week one's roster and had to find today's session in a list.
 * Now, with no `?session=` in the address, it opens on:
 *
 *   1. the session that is LIVE — AttendanceWindow::isLive, S ≤ now ≤ E, never
 *      a cancelled one (the definition the live pill and the poll already use);
 *   2. else the NEXT session — the earliest one that has not started;
 *   3. else the LAST session held.
 *
 * Which session is opened decides nothing about anybody's attendance: no window,
 * no status and no counting rule is touched, and a `?session=` in the address
 * always wins. The definition of "live" is the window service's, asked, not
 * restated. Cancelled sessions are never the default (a roster of a session that
 * will not happen is not what anyone opens the screen for) — recorded as an
 * assumption in D-142.
 *
 * @see BR-07, BR-08 · PRD §9.9.7 · CONSTITUTION art. 5 · D-142
 */

use App\Enums\SessionStatus;

beforeEach(function (): void {
    $this->cohort = makeCohort(['status' => 'running']);
    $this->trainer = makeTrainer($this->cohort);

    // Two evenings, Riyadh time.
    $this->a = sessionInCohort($this->cohort, riyadhAt('2026-10-05 18:00:00'), riyadhAt('2026-10-05 21:00:00'), ['title' => 'Session A']);
    $this->b = sessionInCohort($this->cohort, riyadhAt('2026-10-06 18:00:00'), riyadhAt('2026-10-06 21:00:00'), ['title' => 'Session B']);
});

/** The id of the session the roster opened on at `$now`. */
function rosterOpensOn(object $test, string $at, array $query = []): ?string
{
    freezeAt(riyadhAt($at));
    Illuminate\Support\Facades\Auth::forgetGuards();

    // The address without a session is redirected to the one that names it, so the
    // page is read after the hop (see the pinning tests below).
    $roster = $test->actingAs($test->trainer)
        ->followingRedirects()
        ->get(route('trainer.attendance', $query))
        ->assertOk()
        ->viewData('roster');

    return $roster['sessionId'];
}

it('BR-07: قبل بدء الجلسة الأولى بثانية تفتح الشاشة عليها — هي «القادمة»', function (): void {
    expect(rosterOpensOn($this, '2026-10-05 17:59:59'))->toBe($this->a->id);
});

it('BR-07: عند S وعند E بالضبط تفتح على الجلسة الجارية — الحدّان مشمولان', function (): void {
    expect(rosterOpensOn($this, '2026-10-05 18:00:00'))->toBe($this->a->id)
        ->and(rosterOpensOn($this, '2026-10-05 21:00:00'))->toBe($this->a->id);
});

it('BR-09: بعد E بثانية تبقى الجلسة المنتهية للتوّ — الانصراف مفتوح والتصحيح بعدها (D-142)', function (): void {
    expect(rosterOpensOn($this, '2026-10-05 21:00:00'))->toBe($this->a->id)
        ->and(rosterOpensOn($this, '2026-10-05 21:00:01'))->toBe($this->a->id)
        ->and(rosterOpensOn($this, '2026-10-05 21:30:00'))->toBe($this->a->id);
});

it('BR-09: حدّ E+60m — تبقى المنتهية حتى آخر ثانية من نافذة انصرافها ثم تنتقل إلى القادمة', function (): void {
    expect(rosterOpensOn($this, '2026-10-05 21:59:59'))->toBe($this->a->id)
        ->and(rosterOpensOn($this, '2026-10-05 22:00:00'))->toBe($this->a->id)   // E+60m — last instant, inclusive
        ->and(rosterOpensOn($this, '2026-10-05 22:00:01'))->toBe($this->b->id);  // E+60m+1s — window closed
});

it('BR-09: الجارية تغلب المنتهية للتوّ — جلسة تبدأ قبل أن تُغلق نافذة انصراف سابقتها', function (): void {
    // C starts at 21:30, half an hour after A ended (A's check-out is open until 22:00).
    $c = sessionInCohort($this->cohort, riyadhAt('2026-10-05 21:30:00'), riyadhAt('2026-10-05 23:00:00'), ['title' => 'Session C']);

    expect(rosterOpensOn($this, '2026-10-05 21:29:59'))->toBe($this->a->id)
        ->and(rosterOpensOn($this, '2026-10-05 21:30:00'))->toBe($c->id);
});

it('BR-09: من جلستين انتهتا ونافذتاهما مفتوحتان يُفتح ما انتهى آخرًا، والملغاة لا تُفتح', function (): void {
    $late = sessionInCohort($this->cohort, riyadhAt('2026-10-05 19:00:00'), riyadhAt('2026-10-05 21:30:00'), ['title' => 'Overlaps A']);

    // At 21:45 both A (ended 21:00) and `late` (ended 21:30) are still taking check-outs.
    expect(rosterOpensOn($this, '2026-10-05 21:45:00'))->toBe($late->id);

    $late->update(['status' => SessionStatus::Cancelled->value]);

    expect(rosterOpensOn($this, '2026-10-05 21:45:00'))->toBe($this->a->id);
});

it('BR-07: بعد آخر جلسة تفتح على آخر جلسة عُقدت', function (): void {
    expect(rosterOpensOn($this, '2026-10-06 21:00:00'))->toBe($this->b->id)
        ->and(rosterOpensOn($this, '2026-10-06 21:00:01'))->toBe($this->b->id)
        ->and(rosterOpensOn($this, '2026-12-01 09:00:00'))->toBe($this->b->id);
});

it('BR-07: قبل كل الجلسات تفتح على أقدمها بدءًا — لا على آخرها', function (): void {
    expect(rosterOpensOn($this, '2026-09-01 09:00:00'))->toBe($this->a->id);
});

it('BR-07: الجلسة الملغاة لا تكون افتراضًا — لا حيّة ولا قادمة', function (): void {
    $this->b->update(['status' => SessionStatus::Cancelled->value]);

    // Between the two, the next non-cancelled session is none: the last held is A.
    expect(rosterOpensOn($this, '2026-10-05 21:00:01'))->toBe($this->a->id);

    // Live, cancelled: not live, so not the default at its own start.
    expect(rosterOpensOn($this, '2026-10-06 18:00:00'))->toBe($this->a->id);
});

it('BR-07: ما يكتبه المدرّب في العنوان يغلب — جلسة صريحة تُفتح مهما كان الوقت، وجلسة مجهولة تُتجاهل', function (): void {
    expect(rosterOpensOn($this, '2026-10-06 19:00:00', ['session' => $this->a->id]))->toBe($this->a->id)
        ->and(rosterOpensOn($this, '2026-10-06 19:00:00', ['session' => 'not-a-session']))->toBe($this->b->id);
});

it('BR-23: جلسة دفعة أخرى في العنوان لا تُفتح — يبقى الافتراض من جلسات دفعته', function (): void {
    $foreign = sessionInCohort(makeCohort(), riyadhAt('2026-10-06 18:00:00'), riyadhAt('2026-10-06 21:00:00'), ['title' => 'Foreign']);

    expect(rosterOpensOn($this, '2026-10-06 19:00:00', ['session' => $foreign->id]))->toBe($this->b->id);
})->group('authz');

it('BR-07: قائمة الاختيار تُظهر الجلسة المفتوحة فعلًا — لا «اختر من القائمة» فوق كشف جلسة بعينها', function (): void {
    freezeAt(riyadhAt('2026-10-06 19:00:00'));

    $page = $this->actingAs($this->trainer)->followingRedirects()->get(route('trainer.attendance'))->assertOk();

    // What the picker RENDERS, not only what the controller handed it: the hidden
    // input the form submits carries the open session.
    expect($page->viewData('selectedSessionId'))->toBe($this->b->id)
        ->and($page->getContent())->toMatch('/name="session"[^>]*value="'.preg_quote($this->b->id, '/').'"/');
});

it('BR-07: العنوان بلا جلسة يُحوَّل إلى عنوان يسمّيها ويحفظ بقية الاستعلام — فلا يقلب انتهاء الجلسة كشفًا إلى آخر', function (): void {
    freezeAt(riyadhAt('2026-10-06 19:00:00'));

    $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['cohort' => $this->cohort->id]))
        ->assertRedirect(route('trainer.attendance', ['cohort' => $this->cohort->id, 'session' => $this->b->id]));

    // An address that already names a valid session is left alone — no redirect loop.
    Illuminate\Support\Facades\Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['session' => $this->a->id]))
        ->assertOk();

    // A session that is not this cohort's is not honoured: it is pinned to the default.
    Illuminate\Support\Facades\Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['session' => 'not-a-session']))
        ->assertRedirect(route('trainer.attendance', ['session' => $this->b->id]));
});

it('BR-27: فشل التسجيل الجماعي بعد انتهاء الجلسة يعود إلى كشفها هي — لا إلى الجلسة التالية', function (): void {
    // 20:55 — session A is live; the trainer opens the roster with no session in
    // the address, and is pinned to A.
    freezeAt(riyadhAt('2026-10-05 20:55:00'));

    $pinned = $this->actingAs($this->trainer)
        ->get(route('trainer.attendance'))
        ->assertRedirect()
        ->headers->get('Location');

    // 21:05 — A has ended; "the default" is now B. The form is refused (a reason
    // that is too short) and Laravel sends the person back to where they were.
    Illuminate\Support\Facades\Auth::forgetGuards();
    freezeAt(riyadhAt('2026-10-05 21:05:00'));
    $participant = makeParticipant($this->cohort);

    $page = $this->actingAs($this->trainer)
        ->from($pinned)
        ->followingRedirects()
        ->post(route('trainer.attendance.bulk', $this->a), [
            'user_id' => [$participant->id],
            'attendance_status' => 'absent',
            'edit_reason' => 'short',
        ])
        ->assertOk();

    expect($page->viewData('roster')['sessionId'])->toBe($this->a->id);
});

it('BR-07: إن أُلغيت كل الجلسات تُعرض آخرها — الشاشة لا تخلو من كشف الدفعة', function (): void {
    $this->a->update(['status' => SessionStatus::Cancelled->value]);
    $this->b->update(['status' => SessionStatus::Cancelled->value]);

    expect(rosterOpensOn($this, '2026-10-05 19:00:00'))->toBe($this->b->id);
});

it('BR-07: دفعة بلا جلسات — لا كشف ولا خطأ', function (): void {
    $cohort = makeCohort(['status' => 'running']);
    $trainer = makeTrainer($cohort);

    freezeAt(riyadhAt('2026-10-05 19:00:00'));

    // Nothing to name, so nothing to pin: the page itself, not a redirect.
    $roster = $this->actingAs($trainer)->get(route('trainer.attendance'))->assertOk()->viewData('roster');

    expect($roster['hasNoSession'])->toBeTrue();
});

it('BR-07: جلستان تبدآن في اللحظة نفسها — الافتراض ثابت بالمعرّف لا بترتيب الإدخال', function (): void {
    $cohort = makeCohort(['status' => 'running']);
    $trainer = makeTrainer($cohort);
    $start = riyadhAt('2026-10-12 18:00:00');
    $end = riyadhAt('2026-10-12 21:00:00');

    // Inserted with the HIGHER id first, so insertion order is the opposite of id order.
    sessionInCohort($cohort, $start, $end, ['id' => '00000000-0000-7000-8000-000000000002', 'title' => 'Two']);
    $low = sessionInCohort($cohort, $start, $end, ['id' => '00000000-0000-7000-8000-000000000001', 'title' => 'One']);

    freezeAt($start->addMinutes(10));

    $roster = $this->actingAs($trainer)->followingRedirects()->get(route('trainer.attendance'))->assertOk()->viewData('roster');

    expect($roster['sessionId'])->toBe($low->id);
});
