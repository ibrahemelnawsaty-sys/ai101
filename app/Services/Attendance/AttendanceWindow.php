<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\SessionStatus;
use App\Models\Session;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;

/**
 * The single source of truth for attendance time windows.
 *
 * Let S be the session start and E the session end, both stored as a Riyadh
 * calendar date plus Riyadh wall-clock times and resolved to UTC by Clock.
 *
 *   check-in   : [S - 60m, E]          inclusive at both ends
 *   present    :  at <= S + 30m
 *   late       :  at >  S + 30m
 *   check-out  : [E - 30m, E + 60m]    inclusive at both ends
 *
 * Boundary table (CONTRACT §6, PRD §9.9.3):
 *
 *   S-60m-1s   check-in closed
 *   S-60m      check-in open, present
 *   S+30m      check-in open, present  (last instant)
 *   S+30m+1s   check-in open, late
 *   E-30m      check-in open, late, check-out opens
 *   E          check-in open (last instant), check-out open
 *   E+1s       check-in closed, check-out open
 *   E+60m      check-out open (last instant)
 *   E+60m+1s   check-out closed
 *
 * @see BR-01, BR-02, BR-03, BR-04, BR-07 · D-103 · PRD §9.9.2, §9.9.3 · CONTRACT §6
 */
final class AttendanceWindow
{
    /*
     | The door is an hour wide on each side, by the owner's decision of
     | 10 September 2026 (D-103), which supersedes the thirty minutes BR-01 and
     | BR-04 were written with. A lecture published as 5–7pm accepts attendance
     | from 4pm to 8pm.
     |
     | These stay CONSTANTS rather than moving to config/athar.php, and that is
     | deliberate. A value in `.env` can be mistyped, and a mistyped attendance
     | window silently changes who counts as present, which changes attendance
     | rates, which changes who gets a certificate (BR-26). Pinned here, the
     | boundary table in tests/Unit/Services/AttendanceWindowTest.php asserts
     | every edge at ±1 second and fails the moment anyone moves one.
     */
    public const CHECK_IN_OPENS_BEFORE_START_MINUTES = 60;   // BR-01, D-103

    /*
     | UNCHANGED at thirty minutes, and not part of D-103. A wider door is not a
     | longer grace period: someone arriving at 5:31pm is still late, exactly as
     | before. Widening this instead would have quietly reclassified latecomers
     | as present and inflated attendance rates.
     */
    public const LATE_AFTER_START_MINUTES = 30;              // BR-02, BR-03

    /** Unchanged: when check-out becomes possible, not when it stops. */
    public const CHECK_OUT_OPENS_BEFORE_END_MINUTES = 30;    // BR-04

    public const CHECK_OUT_CLOSES_AFTER_END_MINUTES = 60;    // BR-04, D-103

    /**
     * S — the session start, in UTC.
     */
    public function startsAt(Session $session): CarbonImmutable
    {
        return Clock::composeRiyadh(
            $session->getAttribute('date'),
            $session->getAttribute('start_time'),
        );
    }

    /**
     * E — the session end, in UTC.
     */
    public function endsAt(Session $session): CarbonImmutable
    {
        return Clock::composeRiyadh(
            $session->getAttribute('date'),
            $session->getAttribute('end_time'),
        );
    }

    /**
     * S + 30m — the last instant that still counts as present (BR-02).
     */
    public function lateThresholdAt(Session $session): CarbonImmutable
    {
        return $this->startsAt($session)->addMinutes(self::LATE_AFTER_START_MINUTES);
    }

    /** S - 60m */
    public function checkInOpensAt(Session $session): CarbonImmutable
    {
        return $this->startsAt($session)->subMinutes(self::CHECK_IN_OPENS_BEFORE_START_MINUTES);
    }

    /** E */
    public function checkInClosesAt(Session $session): CarbonImmutable
    {
        return $this->endsAt($session);
    }

    /** E - 30m */
    public function checkOutOpensAt(Session $session): CarbonImmutable
    {
        return $this->endsAt($session)->subMinutes(self::CHECK_OUT_OPENS_BEFORE_END_MINUTES);
    }

    /** E + 60m */
    public function checkOutClosesAt(Session $session): CarbonImmutable
    {
        return $this->endsAt($session)->addMinutes(self::CHECK_OUT_CLOSES_AFTER_END_MINUTES);
    }

    /**
     * BR-01 — the check-in window is closed for a cancelled session, and is
     * inclusive of both S-60m and E (D-103).
     */
    public function canCheckIn(Session $session, CarbonImmutable $at): bool
    {
        if ($this->isCancelled($session)) {
            return false;
        }

        return $at->greaterThanOrEqualTo($this->checkInOpensAt($session))
            && $at->lessThanOrEqualTo($this->checkInClosesAt($session));
    }

    /**
     * BR-04 — inclusive of both E-30m and E+60m (D-103).
     */
    public function canCheckOut(Session $session, CarbonImmutable $at): bool
    {
        if ($this->isCancelled($session)) {
            return false;
        }

        return $at->greaterThanOrEqualTo($this->checkOutOpensAt($session))
            && $at->lessThanOrEqualTo($this->checkOutClosesAt($session));
    }

    /**
     * BR-02 / BR-03 — present up to and including S+30m, late after it.
     *
     * Pure classification: it does not re-check the window, because the caller
     * has already refused any instant outside it.
     */
    public function classify(Session $session, CarbonImmutable $at): AttendanceStatus
    {
        return $at->lessThanOrEqualTo($this->lateThresholdAt($session))
            ? AttendanceStatus::Present
            : AttendanceStatus::Late;
    }

    /**
     * Seconds remaining until the check-in window opens; zero once it has.
     */
    public function secondsUntilCheckInOpens(Session $session, CarbonImmutable $at): int
    {
        return max(0, $this->checkInOpensAt($session)->getTimestamp() - $at->getTimestamp());
    }

    /**
     * Seconds remaining until the check-out window opens; zero once it has.
     */
    public function secondsUntilCheckOutOpens(Session $session, CarbonImmutable $at): int
    {
        return max(0, $this->checkOutOpensAt($session)->getTimestamp() - $at->getTimestamp());
    }

    /**
     * True once the session end has passed — the trigger for BR-08.
     */
    public function hasEnded(Session $session, CarbonImmutable $at): bool
    {
        return $at->greaterThan($this->endsAt($session));
    }

    /**
     * True once E+60m has passed — the trigger for BR-09 (D-103).
     */
    public function checkOutWindowHasClosed(Session $session, CarbonImmutable $at): bool
    {
        return $at->greaterThan($this->checkOutClosesAt($session));
    }

    /**
     * True between S and E — used by the live session card.
     */
    public function isLive(Session $session, CarbonImmutable $at): bool
    {
        if ($this->isCancelled($session)) {
            return false;
        }

        return $at->greaterThanOrEqualTo($this->startsAt($session))
            && $at->lessThanOrEqualTo($this->endsAt($session));
    }

    public function isCancelled(Session $session): bool
    {
        return $this->statusOf($session) === SessionStatus::Cancelled;
    }

    private function statusOf(Session $session): ?SessionStatus
    {
        $status = $session->getAttribute('status');

        if ($status instanceof SessionStatus) {
            return $status;
        }

        return is_string($status) ? SessionStatus::tryFrom($status) : null;
    }
}
