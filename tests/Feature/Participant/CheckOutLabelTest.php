<?php

declare(strict_types=1);

use App\Presenters\Participant\AttendanceWindowPresenter;
use App\Services\Attendance\AttendanceWindow;
use Carbon\CarbonImmutable;

/**
 * What the check-out button SAYS before there is anything to check out of (D-132).
 *
 * The button used to read «check-out time has ended» for a participant who had
 * not yet checked in — even for a session four days away — because the state
 * `closed` meant both "its time passed" and "not available yet, no check-in".
 * The rules did not change: BR-05 (no check-out without a check-in) still
 * decides `canCheckOut`, and the window still opens at E-30m and closes at
 * E+60m. Only the WORD changes: while the check-in window is still ahead of the
 * participant (BR-01, D-103: S-60m to E, inclusive), the button says the
 * check-out follows the check-in; once that window is over with no record, or
 * once a check-out is truly over, it says what it always said.
 *
 * S = 18:00, E = 21:00 on 12 October 2026 (Riyadh).
 *
 * @see D-132, D-103 · BR-01, BR-04, BR-05 · PRD §9.9.8 · CONSTITUTION Articles 4, 5
 */
beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
    $this->start = riyadhAt('2026-10-12 18:00:00');
    $this->end = riyadhAt('2026-10-12 21:00:00');
    $this->session = sessionInCohort($this->cohort, $this->start, $this->end);
    $this->window = app(AttendanceWindow::class);
});

/** The presenter at $at, for a participant with no record unless $record says otherwise. */
function checkOutViewAt(object $test, CarbonImmutable $at, mixed $record = null): AttendanceWindowPresenter
{
    return AttendanceWindowPresenter::from($test->session, $record, $test->window, $at);
}

it('D-132: بلا سجل حضور وقبل انتهاء نافذة الحضور، الانصراف «بعد تسجيل الحضور» عند كل حدّ ±1 ثانية', function (): void {
    $moments = [
        'four days before' => $this->start->subDays(4),
        'S-60m-1s' => $this->start->subMinutes(60)->subSecond(),
        'S-60m' => $this->start->subMinutes(60),
        'S-60m+1s' => $this->start->subMinutes(60)->addSecond(),
        'S' => $this->start,
        'E-1s' => $this->end->subSecond(),
        'E' => $this->end, // the check-in window is inclusive of E (D-103)
    ];

    $wrong = [];

    foreach ($moments as $label => $at) {
        $view = checkOutViewAt($this, $at);

        if ($view->checkOutAwaitsCheckIn !== true) {
            $wrong[] = "{$label}: expected the check-out to await the check-in";
        }

        // Nothing the rules decide moved: no check-out without a record (BR-05).
        if ($view->canCheckOut !== false) {
            $wrong[] = "{$label}: canCheckOut must stay false with no record";
        }

        // The look is unchanged: still the disabled `closed` state.
        if ($view->checkOutState !== 'closed') {
            $wrong[] = "{$label}: checkOutState is {$view->checkOutState}, expected closed";
        }
    }

    expect($wrong)->toBe([]);
});

it('D-132: بعد E+1s بلا سجل ينتهي «الانتظار»، فيعود النص المعتاد لأن نافذة الحضور فاتت', function (): void {
    foreach ([
        'E+1s' => $this->end->addSecond(),
        'E+30m' => $this->end->addMinutes(30),
        'E+60m' => $this->end->addMinutes(60),
        'E+60m+1s' => $this->end->addMinutes(60)->addSecond(),
    ] as $label => $at) {
        $view = checkOutViewAt($this, $at);

        expect($view->checkOutAwaitsCheckIn)->toBeFalse($label)
            ->and($view->canCheckOut)->toBeFalse($label)
            ->and($view->checkOutState)->toBe('closed', $label);
    }
});

it('D-132: من سجّل حضوره لا يُقال له «بعد تسجيل الحضور» أبدًا', function (): void {
    $record = makeAttendance($this->session, $this->participant, 'present');

    foreach ([$this->start->subDays(4), $this->start, $this->end->subMinutes(30), $this->end, $this->end->addMinutes(60)->addSecond()] as $at) {
        expect(checkOutViewAt($this, $at, $record)->checkOutAwaitsCheckIn)->toBeFalse();
    }
});

it('D-132: جلسة ملغاة لا تنتظر شيئًا', function (): void {
    $cancelled = sessionInCohort($this->cohort, $this->start, $this->end, ['status' => 'cancelled']);

    $view = AttendanceWindowPresenter::from($cancelled, null, $this->window, $this->start->subDays(1));

    expect($view->isCancelled)->toBeTrue()
        ->and($view->checkOutAwaitsCheckIn)->toBeFalse();
});

it('D-132: الشاشة لجلسة بعد أيام تقول «الانصراف بعد تسجيل الحضور» ولا تقول «انتهى وقت تسجيل الانصراف»', function (): void {
    freezeAt($this->start->subDays(4));

    $html = $this->actingAs($this->participant)->get(route('attendance.index'))->assertOk()->getContent();

    expect($html)->toContain(__('attendance.check_out_after_check_in'))
        ->and($html)->not->toContain(__('attendance.check_out_closed'));
});

it('D-132: بعد فوات نافذة الحضور بلا سجل تعود الشاشة إلى «انتهى وقت تسجيل الانصراف»', function (): void {
    freezeAt($this->end->addMinutes(10));

    $html = $this->actingAs($this->participant)->get(route('attendance.index'))->assertOk()->getContent();

    expect($html)->toContain(__('attendance.check_out_closed'))
        ->and($html)->not->toContain(__('attendance.check_out_after_check_in'));
});
