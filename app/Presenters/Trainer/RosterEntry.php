<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One person on a session's roster.
 *
 * A participant with no attendance row yet is still a row here, with no times
 * and no status — the roster is the list of people expected at the session, not
 * the list of rows that happen to exist. Showing only the recorded ones would
 * hide exactly the people the trainer is looking for.
 *
 * `attendanceId` is what the manual-edit endpoint addresses; it is null until
 * a row exists, and the edit button is only offered when it does.
 *
 * @see BR-08, BR-09, BR-10, BR-23 · PRD §9.9.7 · CONSTITUTION art. 17, art. 18
 */
final class RosterEntry extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(User $participant, ?Attendance $record): self
    {
        $profile = self::related($participant, 'profile');

        $status = $record?->getAttribute('status');
        $status = $status instanceof AttendanceStatus ? $status : null;

        return new self([
            'participantId' => (string) $participant->getKey(),
            'participantName' => (string) (
                $profile?->getAttribute('full_name_ar') ?? $participant->getAttribute('email')
            ),
            'attendanceId' => $record === null ? null : (string) $record->getKey(),
            'checkedInAt' => $record?->getAttribute('check_in_at'),
            'checkedOutAt' => $record?->getAttribute('check_out_at'),
            'statusLabel' => $status?->label() ?? __('attendance.status.not_recorded'),
            'statusVariant' => self::attendanceVariantOf($status),
            'statusIcon' => self::attendanceIconOf($status),
        ]);
    }
}
