<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Enums\AttendanceExceptionType;
use App\Models\Attendance;
use App\Models\AttendanceExceptionRequest;
use App\Services\Attendance\AttendanceExceptionRequester;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A participant asking to be excused for an absence or an unexcused
 * lateness on one of their own attendance records (D-106).
 *
 * Which of the two the button offers is decided by the record's own status —
 * the service re-checks that match server-side (art. 5); this request only
 * shapes the two fields the form actually sends.
 *
 * @see D-106
 */
final class StoreAttendanceExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendance = $this->route('attendance');

        return $attendance instanceof Attendance
            && $this->user() !== null
            && $this->user()->can('create', [AttendanceExceptionRequest::class, $attendance]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(AttendanceExceptionType::values())],
            'reason' => ['required', 'string', 'min:'.AttendanceExceptionRequester::MIN_REASON_LENGTH, 'max:1000'],
        ];
    }

    public function type(): AttendanceExceptionType
    {
        /** @var AttendanceExceptionType $type */
        $type = AttendanceExceptionType::from((string) $this->validated('type'));

        return $type;
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
