<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
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

        return new self([
            'sessionTitle' => $session instanceof Session
                ? (string) $session->getAttribute('title')
                : '',
            'sessionDate' => $session instanceof Session
                ? Present::toDateTime($session->getAttribute('date'))
                : null,
            'checkedInAt' => Present::toDateTime($attendance->getAttribute('check_in_at')),
            'checkedOutAt' => Present::toDateTime($attendance->getAttribute('check_out_at')),
            'statusLabel' => $status?->label() ?? '',
            'statusVariant' => match ($status) {
                AttendanceStatus::Present => 'success',
                AttendanceStatus::Excused => 'info',
                AttendanceStatus::Late, AttendanceStatus::Incomplete => 'warning',
                default => 'error',
            },
            'statusIcon' => match ($status) {
                AttendanceStatus::Present => 'check',
                AttendanceStatus::Excused => 'info',
                AttendanceStatus::Late, AttendanceStatus::Incomplete => 'clock',
                default => 'warn',
            },
            'note' => Present::text($attendance->getAttribute('edit_reason')),
        ]);
    }
}
