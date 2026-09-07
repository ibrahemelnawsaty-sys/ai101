<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Attendance;
use App\Models\Session;
use App\Presenters\Support\Present;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Time\Clock;
use App\Support\Dates;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * The two attendance buttons and the sentence beneath them (PRD §9.9.4).
 *
 * Every boolean here is AttendanceWindow's answer at Clock::now(), rendered.
 * The POST endpoints ask the same service again on arrival, so a page rendered
 * ten minutes ago cannot buy a check-in: what this object carries is a
 * reflection of a past decision, never the decision (BR-07, art. 5).
 *
 * `checkInState` and `checkOutState` are the four states the CSS knows —
 * done · open · wait · closed (resources/css/screens.css, .checkin[data-state]).
 * PRD §9.9.4 forbids hiding a button without saying why, so `explanation` is
 * always populated with the matching §9.9.8 sentence.
 *
 * @see BR-01, BR-02, BR-03, BR-04, BR-05, BR-06, BR-07 · PRD §9.9.2, §9.9.3, §9.9.4, §9.9.8
 */
final class AttendanceWindowPresenter extends ViewModel
{
    public static function noSession(): self
    {
        return new self([
            'hasNoSession' => true,
            'isToday' => false,
            'isCancelled' => false,
            'sessionId' => null,
            'sessionTitle' => null,
            'startsAt' => null,
            'endsAt' => null,
            'canCheckIn' => false,
            'canCheckOut' => false,
            'checkInState' => 'closed',
            'checkOutState' => 'closed',
            'checkInOpensAt' => null,
            'checkOutOpensAt' => null,
            'checkedInAt' => null,
            'checkedOutAt' => null,
            'explanation' => (string) __('attendance.no_session_body'),
        ]);
    }

    public static function from(
        Session $session,
        ?Attendance $record,
        AttendanceWindow $window,
        CarbonImmutable $now,
    ): self {
        $isCancelled = $window->isCancelled($session);
        $checkedInAt = $record === null ? null : Present::toDateTime($record->getAttribute('check_in_at'));
        $checkedOutAt = $record === null ? null : Present::toDateTime($record->getAttribute('check_out_at'));

        // BR-06 — one record per participant per session, so an existing record
        // closes check-in for good; BR-05 — no check-out without one.
        $canCheckIn = ! $isCancelled && $record === null && $window->canCheckIn($session, $now);
        $canCheckOut = ! $isCancelled
            && $record !== null
            && $checkedOutAt === null
            && $window->canCheckOut($session, $now);

        $checkInOpensAt = $window->checkInOpensAt($session);
        $checkOutOpensAt = $window->checkOutOpensAt($session);

        $checkInState = self::state(
            done: $checkedInAt !== null,
            open: $canCheckIn,
            waiting: ! $isCancelled && $now->lessThan($checkInOpensAt),
        );

        $checkOutState = self::state(
            done: $checkedOutAt !== null,
            open: $canCheckOut,
            waiting: ! $isCancelled && $record !== null && $now->lessThan($checkOutOpensAt),
        );

        return new self([
            'hasNoSession' => false,
            'isToday' => Clock::toRiyadh($window->startsAt($session))->toDateString()
                === Clock::toRiyadh($now)->toDateString(),
            'isCancelled' => $isCancelled,
            'sessionId' => (string) $session->getKey(),
            'sessionTitle' => (string) $session->getAttribute('title'),
            'startsAt' => $window->startsAt($session),
            'endsAt' => $window->endsAt($session),
            'canCheckIn' => $canCheckIn,
            'canCheckOut' => $canCheckOut,
            'checkInState' => $checkInState,
            'checkOutState' => $checkOutState,
            'checkInOpensAt' => $checkInOpensAt,
            'checkOutOpensAt' => $checkOutOpensAt,
            'checkedInAt' => $checkedInAt,
            'checkedOutAt' => $checkedOutAt,
            'explanation' => self::explanation(
                $session,
                $window,
                $now,
                $isCancelled,
                $checkedInAt,
                $checkedOutAt,
                $checkInState,
                $checkOutState,
            ),
        ]);
    }

    private static function state(bool $done, bool $open, bool $waiting): string
    {
        if ($done) {
            return 'done';
        }

        if ($open) {
            return 'open';
        }

        return $waiting ? 'wait' : 'closed';
    }

    /**
     * The PRD §9.9.8 sentence that matches the current pair of states. Each one
     * is quoted verbatim from lang/ar/attendance.php; none is composed here.
     */
    private static function explanation(
        Session $session,
        AttendanceWindow $window,
        CarbonImmutable $now,
        bool $isCancelled,
        mixed $checkedInAt,
        mixed $checkedOutAt,
        string $checkInState,
        string $checkOutState,
    ): string {
        if ($isCancelled) {
            return (string) __('attendance.messages.session_cancelled');
        }

        if ($checkedOutAt !== null) {
            return (string) __('attendance.messages.check_out_success', [
                'time' => Dates::time12(Present::toDateTime($checkedOutAt)),
            ]);
        }

        if ($checkedInAt !== null) {
            if ($checkOutState === 'wait') {
                return (string) __('attendance.messages.check_out_before_window');
            }

            if ($checkOutState === 'closed') {
                return (string) __('attendance.messages.check_out_after_window');
            }

            return (string) __('attendance.messages.check_in_success', [
                'time' => Dates::time12(Present::toDateTime($checkedInAt)),
            ]);
        }

        if ($checkInState === 'wait') {
            return (string) __('attendance.messages.check_in_before_window', [
                'countdown' => Present::countdown($window->checkInOpensAt($session), $now),
            ]);
        }

        if ($checkInState === 'closed') {
            return (string) __('attendance.messages.check_in_after_window');
        }

        return (string) __('attendance.window.check_in_rule', [
            'minutes' => (string) AttendanceWindow::CHECK_IN_OPENS_BEFORE_START_MINUTES,
        ]);
    }
}
