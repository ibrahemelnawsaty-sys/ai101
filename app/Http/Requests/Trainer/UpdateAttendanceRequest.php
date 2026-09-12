<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual correction to one attendance row (BR-10).
 *
 * Two things are non-negotiable: a written reason of at least ten characters,
 * and the fact that the correction is recorded as manual with its author. The
 * row is never rewritten silently — the previous values go into audit_logs
 * before the change lands.
 *
 * No time is accepted: PRD §9.9.4 takes no time value from the client. The
 * two optional instants this used to validate were never applied by anything,
 * and their format rule refused what the form sent (D-72).
 *
 * @see BR-07, BR-10, BR-23, BR-27 · PRD §9.9.7 · CONSTITUTION Art. 8, Art. 11
 */
final class UpdateAttendanceRequest extends FormRequest
{
    /** BR-10: a manual edit needs a real reason, not a character. */
    public const MIN_REASON_LENGTH = 10;

    public function authorize(): bool
    {
        $attendance = $this->route('attendance');
        $user = $this->user();

        return $attendance instanceof Attendance
            && $user !== null
            && $user->can('update', $attendance);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('edit_reason');

        if (is_string($reason)) {
            $this->merge(['edit_reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attendance_status' => ['required', Rule::enum(AttendanceStatus::class)],
            'edit_reason' => ['required', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000'],
        ];
    }

    public function attendance(): Attendance
    {
        /** @var Attendance $attendance */
        $attendance = $this->route('attendance');

        return $attendance;
    }

    public function status(): AttendanceStatus
    {
        return AttendanceStatus::from((string) $this->validated('attendance_status'));
    }

    public function reason(): string
    {
        return (string) $this->validated('edit_reason');
    }
}
