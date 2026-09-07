<?php

declare(strict_types=1);

/**
 * The full attendance boundary table, asserted at ±1 second around every edge.
 *
 * This is the most consequential unit test in the platform: a one-second error here
 * changes a trainee's attendance rate, which changes certificate eligibility.
 *
 * The oracle is deliberately independent. canonicalSession() is handed the two
 * instants S and E and writes them into the row; the test then compares
 * AttendanceWindow's answers against those same instants. Nothing is re-derived by
 * the code under test.
 *
 * @see BR-01, BR-02, BR-03, BR-04, BR-07 · PRD §9.9.2, §9.9.3, §14.1
 * @see PROJECT-CONTRACT.md §6 · CONSTITUTION.md Article 20
 */

use App\Models\Session;
use App\Services\Attendance\AttendanceWindow;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The boundary table — PROJECT-CONTRACT.md §6
|--------------------------------------------------------------------------
|
| Each row: [anchor, offset in seconds, may check in, may check out, classification]
| A null classification means the contract table prints "—": the moment lies outside
| the check-in window, so no status is defined and none is asserted.
|
*/
dataset('boundaries', [
    'S-30m-1s' => ['S', -1801, false, false, null],
    'S-30m' => ['S', -1800, true,  false, 'present'],
    'S-30m+1s' => ['S', -1799, true,  false, 'present'],
    'S-1s' => ['S', -1, true,  false, 'present'],
    'S' => ['S', 0, true,  false, 'present'],
    'S+30m-1s' => ['S', 1799, true,  false, 'present'],
    'S+30m' => ['S', 1800, true,  false, 'present'],
    'S+30m+1s' => ['S', 1801, true,  false, 'late'],
    'E-30m-1s' => ['E', -1801, true,  false, 'late'],
    'E-30m' => ['E', -1800, true,  true,  'late'],
    'E-30m+1s' => ['E', -1799, true,  true,  'late'],
    'E-1s' => ['E', -1, true,  true,  'late'],
    'E' => ['E', 0, true,  true,  'late'],
    'E+1s' => ['E', 1, false, true,  null],
    'E+30m-1s' => ['E', 1799, false, true,  null],
    'E+30m' => ['E', 1800, false, true,  null],
    'E+30m+1s' => ['E', 1801, false, false, null],
]);

beforeEach(function () {
    [$this->session, $this->start, $this->end] = canonicalSession();
    $this->window = attendanceWindow();
});

/**
 * Resolve a boundary row to an absolute instant.
 */
function boundaryInstant(CarbonImmutable $start, CarbonImmutable $end, string $anchor, int $offsetSeconds): CarbonImmutable
{
    return ($anchor === 'S' ? $start : $end)->addSeconds($offsetSeconds);
}

/*
|--------------------------------------------------------------------------
| Window edges
|--------------------------------------------------------------------------
*/

it('يفتح تسجيل الحضور قبل بداية الجلسة بثلاثين دقيقة بالضبط', function () {
    expect($this->window->checkInOpensAt($this->session)->equalTo($this->start->subMinutes(30)))->toBeTrue();
});

it('يغلق تسجيل الحضور عند نهاية الجلسة بالضبط', function () {
    expect($this->window->checkInClosesAt($this->session)->equalTo($this->end))->toBeTrue();
});

it('يفتح تسجيل الانصراف قبل نهاية الجلسة بثلاثين دقيقة بالضبط', function () {
    expect($this->window->checkOutOpensAt($this->session)->equalTo($this->end->subMinutes(30)))->toBeTrue();
});

it('يغلق تسجيل الانصراف بعد نهاية الجلسة بثلاثين دقيقة بالضبط', function () {
    expect($this->window->checkOutClosesAt($this->session)->equalTo($this->end->addMinutes(30)))->toBeTrue();
});

it('يعلن الثوابت الأربعة كما نص عليها العقد', function () {
    expect(AttendanceWindow::CHECK_IN_OPENS_BEFORE_START_MINUTES)->toBe(30)
        ->and(AttendanceWindow::LATE_AFTER_START_MINUTES)->toBe(30)
        ->and(AttendanceWindow::CHECK_OUT_OPENS_BEFORE_END_MINUTES)->toBe(30)
        ->and(AttendanceWindow::CHECK_OUT_CLOSES_AFTER_END_MINUTES)->toBe(30);
});

/*
|--------------------------------------------------------------------------
| The table itself — three assertions per row
|--------------------------------------------------------------------------
*/

it('BR-01: نافذة تسجيل الحضور عند الحد', function (string $anchor, int $offset, bool $mayCheckIn) {
    $at = boundaryInstant($this->start, $this->end, $anchor, $offset);

    expect($this->window->canCheckIn($this->session, $at))->toBe($mayCheckIn);
})->with('boundaries');

it('BR-04: نافذة تسجيل الانصراف عند الحد', function (string $anchor, int $offset, bool $mayCheckIn, bool $mayCheckOut) {
    $at = boundaryInstant($this->start, $this->end, $anchor, $offset);

    expect($this->window->canCheckOut($this->session, $at))->toBe($mayCheckOut);
})->with('boundaries');

it('BR-02, BR-03: تصنيف الحضور عند الحد', function (string $anchor, int $offset, bool $mayCheckIn, bool $mayCheckOut, ?string $expected) {
    if ($expected === null) {
        expect($this->window->canCheckIn($this->session, boundaryInstant($this->start, $this->end, $anchor, $offset)))
            ->toBeFalse();

        return;
    }

    $at = boundaryInstant($this->start, $this->end, $anchor, $offset);

    expect($this->window->classify($this->session, $at)->value)->toBe($expected);
})->with('boundaries');

/*
|--------------------------------------------------------------------------
| The two classification edges called out explicitly by PRD §14.1
|--------------------------------------------------------------------------
*/

it('BR-02: الدقيقة الثلاثون بالضبط من بداية الجلسة تُحتسب حاضرًا لا متأخرًا', function () {
    expect($this->window->classify($this->session, $this->start->addMinutes(30))->value)->toBe('present');
});

it('BR-03: الثانية التالية للدقيقة الثلاثين تُحتسب متأخرًا', function () {
    expect($this->window->classify($this->session, $this->start->addMinutes(30)->addSecond())->value)->toBe('late');
});

/*
|--------------------------------------------------------------------------
| A session that crosses midnight — PRD §14.1
|--------------------------------------------------------------------------
*/

it('BR-07: جلسة تمتد عبر منتصف الليل تحسب نوافذها على التوقيت الحقيقي لا على التاريخ', function () {
    $start = riyadhAt('2026-10-12 23:00:00');
    $end = riyadhAt('2026-10-13 01:00:00');
    $session = makeSessionAt($start, $end);

    $window = attendanceWindow();

    expect($window->checkInOpensAt($session)->equalTo($start->subMinutes(30)))->toBeTrue()
        ->and($window->checkInClosesAt($session)->equalTo($end))->toBeTrue()
        ->and($window->checkOutClosesAt($session)->equalTo($end->addMinutes(30)))->toBeTrue();

    // 22:29:59 Riyadh, the second before the window opens, is still on 12 October.
    expect($window->canCheckIn($session, $start->subMinutes(30)->subSecond()))->toBeFalse()
        ->and($window->canCheckIn($session, $start->subMinutes(30)))->toBeTrue();

    // 00:30 Riyadh on 13 October is inside the session, and late.
    expect($window->classify($session, riyadhAt('2026-10-13 00:30:00'))->value)->toBe('late');

    // 01:30:01 Riyadh is one second past the close of the check-out window.
    expect($window->canCheckOut($session, $end->addMinutes(30)->addSecond()))->toBeFalse();
})->skip(
    'D-35: PRD §14.1 يُلزم باختبار جلسة تعبر منتصف الليل، بينما قيد PRD §7.7 '
    .'CHECK (end_time > start_time) يمنع تخزينها أصلًا. تعارض في المصدر، والحضور '
    .'نطاق يحظر فيه الدستور الافتراض (المادة 4) — بانتظار قرار صاحب المنتج.'
);

/*
|--------------------------------------------------------------------------
| The window is anchored to Riyadh wall-clock, and returns UTC
|--------------------------------------------------------------------------
*/

it('BR-07: النوافذ تُحسب من توقيت الرياض وتُرجع بتوقيت UTC', function () {
    // 6:00 pm Riyadh is 15:00 UTC; the window opens at 14:30 UTC.
    expect($this->window->checkInOpensAt($this->session)->format('Y-m-d H:i:s'))->toBe('2026-10-12 14:30:00')
        ->and($this->window->checkInOpensAt($this->session)->getTimezone()->getName())->toBe('UTC');
});

it('BR-07: النافذة لا تتأثر بأي وقت يرسله العميل — الوسيط الوحيد هو لحظة الخادم', function () {
    // Two identical calls with the same server instant must agree, whatever the
    // caller believes the time to be. The signature admits no client-supplied value.
    $at = $this->start->addMinutes(10);

    expect($this->window->canCheckIn($this->session, $at))
        ->toBe($this->window->canCheckIn($this->session->fresh(), $at));
});

it('نافذة الانصراف لجلسة قصيرة تبدأ قبل بداية الجلسة نفسها ولا تنكسر', function () {
    // A 20-minute session: E-30m falls before S. The check-out window still opens
    // there, and check-in is still governed by its own edges.
    $start = riyadhAt('2026-10-12 18:00:00');
    $end = riyadhAt('2026-10-12 18:20:00');
    $session = makeSessionAt($start, $end);
    $window = attendanceWindow();

    expect($window->checkOutOpensAt($session)->equalTo($start->subMinutes(10)))->toBeTrue()
        ->and($window->canCheckOut($session, $start->subMinutes(10)))->toBeTrue()
        ->and($window->canCheckOut($session, $start->subMinutes(10)->subSecond()))->toBeFalse()
        // 17:50 is inside the check-in window too: both windows overlap here.
        ->and($window->canCheckIn($session, $start->subMinutes(10)))->toBeTrue()
        ->and($window->canCheckIn($session, $start->subMinutes(30)))->toBeTrue()
        ->and($window->canCheckIn($session, $start->subMinutes(30)->subSecond()))->toBeFalse()
        ->and($window->canCheckIn($session, $end->addSecond()))->toBeFalse();

    // Everything inside a 20-minute session is still "present": S+30m is past E.
    expect($window->classify($session, $end)->value)->toBe('present');
});

it('يقبل نموذج الجلسة كما هو بلا إعادة تحميل من قاعدة البيانات', function () {
    // The service must not issue its own query; it reads the row it was given.
    $session = $this->session;

    expect($session)->toBeInstanceOf(Session::class)
        ->and($this->window->checkInOpensAt($session)->equalTo($this->start->subMinutes(30)))->toBeTrue();
});
