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
 * The two instants are optional; when supplied they are Riyadh wall time and
 * are converted by App\Services\Time\Clock, never parsed here (BR-07).
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
            'checked_in_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'checked_out_at' => ['nullable', 'date_format:Y-m-d H:i'],
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

    public function checkedInAtRiyadh(): ?string
    {
        $value = $this->validated('checked_in_at');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function checkedOutAtRiyadh(): ?string
    {
        $value = $this->validated('checked_out_at');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
