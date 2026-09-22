<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Attendance domain failures.
 *
 * @see BR-01, BR-02, BR-03, BR-04, BR-05, BR-06, BR-10 · D-106 · PRD §9.9.8
 */
final class AttendanceException extends DomainException
{
    public static function sessionCancelled(): self
    {
        return new self('attendance.errors.session_cancelled', [], 422);
    }

    public static function notEnrolled(): self
    {
        return new self('attendance.errors.not_enrolled', [], 403);
    }

    /**
     * BR-01 — the check-in window has not opened yet. The countdown arrives
     * already formatted (HH:MM:SS, Latin digits) from RiyadhFormatter.
     */
    public static function checkInNotOpen(string $countdown): self
    {
        return new self('attendance.errors.check_in_not_open', ['countdown' => $countdown], 422);
    }

    /** BR-01 — the window closed at E. */
    public static function checkInClosed(): self
    {
        return new self('attendance.errors.check_in_closed', [], 422);
    }

    /** BR-06 — one attendance row per user per session. */
    public static function alreadyCheckedIn(?\Throwable $previous = null): self
    {
        return new self('attendance.errors.already_checked_in', [], 409, $previous);
    }

    /**
     * BR-06 — a row already exists for this user and session but it was not
     * created by a check-in (absent or excused), so check-in cannot proceed.
     */
    public static function recordAlreadyExists(?\Throwable $previous = null): self
    {
        return new self('attendance.errors.record_exists', [], 409, $previous);
    }

    /** BR-04 — check-out opens at E-30m. */
    public static function checkOutNotOpen(): self
    {
        return new self('attendance.errors.check_out_not_open', [], 422);
    }

    /** BR-04 — check-out closed at E+30m. */
    public static function checkOutClosed(): self
    {
        return new self('attendance.errors.check_out_closed', [], 422);
    }

    /** BR-05 — no check-out without a prior check-in for the same session. */
    public static function checkOutWithoutCheckIn(): self
    {
        return new self('attendance.errors.check_out_without_check_in', [], 422);
    }

    public static function alreadyCheckedOut(): self
    {
        return new self('attendance.errors.already_checked_out', [], 409);
    }

    /** BR-10 — a manual edit requires a written reason. */
    public static function reasonTooShort(int $minimum): self
    {
        return new self('attendance.errors.reason_too_short', ['min' => $minimum], 422);
    }

    public static function recordNotFound(): self
    {
        return new self('attendance.errors.record_not_found', [], 404);
    }

    /** D-106 — a scan before the session has actually started. */
    public static function selfCheckInNotOpen(): self
    {
        return new self('attendance.errors.self_check_in_not_open', [], 422);
    }

    /** D-106 — the one-hour self-check-in window has closed for good. */
    public static function selfCheckInClosed(): self
    {
        return new self('attendance.errors.self_check_in_closed', [], 422);
    }

    /** D-106 — an excuse request needs a written reason, like a manual edit. */
    public static function exceptionReasonTooShort(int $minimum): self
    {
        return new self('attendance.errors.exception_reason_too_short', ['min' => $minimum], 422);
    }

    /**
     * D-106 — the type requested (absence/lateness) does not match what this
     * attendance record's status currently is. The buttons that lead here are
     * shown or hidden by the same rule server-side, so this is reached only by
     * a request crafted after the record's status has since changed.
     */
    public static function exceptionTypeMismatch(): self
    {
        return new self('attendance.errors.exception_type_mismatch', [], 422);
    }

    /** D-106 — one pending request per attendance record at a time. */
    public static function exceptionAlreadyPending(): self
    {
        return new self('attendance.errors.exception_already_pending', [], 409);
    }

    /** D-106 — only a request still pending may be approved or rejected. */
    public static function exceptionAlreadyDecided(): self
    {
        return new self('attendance.errors.exception_already_decided', [], 409);
    }
}
