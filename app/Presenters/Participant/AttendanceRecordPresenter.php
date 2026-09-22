<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\AttendanceExceptionStatus;
use App\Enums\AttendanceExceptionType;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceExceptionRequest;
use App\Models\Session;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One row of the participant's own attendance log (PRD §9.9.6).
 *
 * The colour never carries the meaning alone: every pill pairs its variant with
 * an icon and the status word itself (art. 18).
 *
 * @see BR-08, BR-09, BR-22 · PRD §9.9.6
 */
final class AttendanceRecordPresenter extends ViewModel
{
    public static function from(Attendance $attendance): self
    {
        $status = $attendance->getAttribute('status');
        $status = $status instanceof AttendanceStatus
            ? $status
            : AttendanceStatus::tryFrom((string) $status);

        $session = $attendance->relationLoaded('session') ? $attendance->getRelation('session') : null;
        $isExcused = $attendance->isExcused();

        /** @var AttendanceExceptionRequest|null $latestRequest */
        $latestRequest = $attendance->relationLoaded('exceptionRequests')
            ? $attendance->getRelation('exceptionRequests')->sortByDesc('created_at')->first()
            : null;
        $requestStatus = $latestRequest?->getAttribute('status');
        // A decided request stops offering the button it came from — a fresh
        // one is only ever for a LATER record, never a reopening of this one.
        $hasOpenOrDecidedRequest = $latestRequest !== null;

        return new self([
            'id' => (string) $attendance->getKey(),
            'sessionTitle' => $session instanceof Session
                ? (string) $session->getAttribute('title')
                : '',
            'sessionDate' => $session instanceof Session
                ? Present::toDateTime($session->getAttribute('date'))
                : null,
            'checkedInAt' => Present::toDateTime($attendance->getAttribute('check_in_at')),
            'checkedOutAt' => Present::toDateTime($attendance->getAttribute('check_out_at')),
            'statusLabel' => $status?->label() ?? '',
            'statusVariant' => match (true) {
                $isExcused => 'info',
                $status === AttendanceStatus::Present => 'success',
                $status === AttendanceStatus::Excused => 'info',
                $status === AttendanceStatus::Late, $status === AttendanceStatus::Incomplete => 'warning',
                default => 'error',
            },
            'statusIcon' => match (true) {
                $isExcused => 'info',
                $status === AttendanceStatus::Present => 'check',
                $status === AttendanceStatus::Excused => 'info',
                $status === AttendanceStatus::Late, $status === AttendanceStatus::Incomplete => 'clock',
                default => 'warn',
            },
            'note' => Present::text($attendance->getAttribute('edit_reason')),

            // D-106 — the excuse-request action for this record, if any applies.
            'isExcused' => $isExcused,
            'canRequestAbsenceException' => ! $isExcused && ! $hasOpenOrDecidedRequest && $status === AttendanceStatus::Absent,
            'canRequestLatenessException' => ! $isExcused && ! $hasOpenOrDecidedRequest && $status === AttendanceStatus::Late,
            'exceptionTypeValue' => match ($status) {
                AttendanceStatus::Absent => AttendanceExceptionType::Absence->value,
                AttendanceStatus::Late => AttendanceExceptionType::Lateness->value,
                default => null,
            },
            'exceptionStatus' => $requestStatus?->value,
            'exceptionPending' => $requestStatus === AttendanceExceptionStatus::Pending,
            'exceptionRejectedReason' => $requestStatus === AttendanceExceptionStatus::Rejected
                ? Present::text($latestRequest?->getAttribute('decision_reason'))
                : null,
        ]);
    }
}
