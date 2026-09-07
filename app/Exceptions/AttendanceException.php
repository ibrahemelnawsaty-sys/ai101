<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Attendance domain failures.
 *
 * @see BR-01, BR-02, BR-03, BR-04, BR-05, BR-06, BR-10 · PRD §9.9.8
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
    public static function alreadyCheckedIn(?Throwable $previous = null): self
    {
        return new self('attendance.errors.already_checked_in', [], 409, $previous);
    }

    /**
     * BR-06 — a row already exists for this user and session but it was not
     * created by a check-in (absent or excused), so check-in cannot proceed.
     */
    public static function recordAlreadyExists(?Throwable $previous = null): self
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
}
