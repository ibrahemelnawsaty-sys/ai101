<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * The single manual-correction form (BR-10).
 *
 * Every manual edit demands a written reason of at least ten characters, and
 * the previous values go into audit_logs BEFORE the new ones land. That is
 * enforced by AttendanceRecorder and by the FormRequest; the `minlength` on the
 * field and the live hint beside it are mirrors of the rule, never the rule
 * (art. 5, art. 8).
 *
 * `id` is the attendance row the PATCH endpoint addresses. The form carries no
 * times: PRD §9.9.4 accepts no time value from the client, and the two time
 * inputs it used to render pre-filled "18:05" against a rule demanding
 * "Y-m-d H:i" — so a checked-in row could never be saved until the trainer
 * blanked both, and the server discarded any time it was given anyway (D-72).
 *
 * @see BR-07, BR-10, BR-23, BR-27 · PRD §9.9.7 · CONSTITUTION art. 5, art. 8, art. 11
 */
final class AttendanceEditForm extends ViewModel
{
    use PresentsFormValues;

    public static function from(Attendance $record, Session $session, User $participant): self
    {
        $profile = self::related($participant, 'profile');

        $status = $record->getAttribute('status');

        return new self([
            'id' => (string) $record->getKey(),
            'sessionId' => (string) $session->getKey(),
            'participantId' => (string) $participant->getKey(),
            'participantName' => (string) (
                $profile?->getAttribute('full_name_ar') ?? $participant->getAttribute('email')
            ),
            'status' => $status instanceof AttendanceStatus ? $status->value : (string) $status,
        ]);
    }
}
