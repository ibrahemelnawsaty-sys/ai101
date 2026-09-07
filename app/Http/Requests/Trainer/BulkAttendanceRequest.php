<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Session;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marking several participants of one session at once (PRD §9.9.7).
 *
 * Every named participant must belong to that session's cohort — the exists
 * rule below carries the cohort in its own where clause, so a crafted payload
 * cannot reach a stranger's row (BR-22, BR-23). A reason is still mandatory:
 * marking ten people by hand is ten manual corrections, not an exception.
 *
 * @see BR-10, BR-22, BR-23, BR-27 · PRD §9.9.7 · CONSTITUTION Art. 8, Art. 22
 */
final class BulkAttendanceRequest extends FormRequest
{
    public const MIN_REASON_LENGTH = 10;

    /** Ceiling on one bulk call, so a request cannot become a long job. */
    public const MAX_ROWS = 200;

    public function authorize(): bool
    {
        $session = $this->route('session');
        $user = $this->user();

        return $session instanceof Session
            && $user !== null
            && $user->can('bulkMark', [Attendance::class, $session]);
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
        $session = $this->route('session');
        $cohortId = $session instanceof Session ? (string) $session->cohort_id : null;

        return [
            'attendance_status' => ['required', Rule::enum(AttendanceStatus::class)],
            'edit_reason' => ['required', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000'],
            'user_id' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'user_id.*' => [
                'required', 'string', 'uuid',
                Rule::exists('enrollments', 'user_id')
                    ->where('cohort_id', $cohortId)
                    ->where('role_in_cohort', 'participant'),
            ],
        ];
    }

    public function trainingSession(): Session
    {
        /** @var Session $session */
        $session = $this->route('session');

        return $session;
    }

    public function status(): AttendanceStatus
    {
        return AttendanceStatus::from((string) $this->validated('attendance_status'));
    }

    public function reason(): string
    {
        return (string) $this->validated('edit_reason');
    }

    /**
     * @return list<string>
     */
    public function userIds(): array
    {
        /** @var list<string> $ids */
        $ids = array_values(array_unique((array) $this->validated('user_id')));

        return $ids;
    }
}
