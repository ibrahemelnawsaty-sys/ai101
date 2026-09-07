<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Models\Attendance;
use App\Models\Session;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording departure from a session. Same shape as the check-in request:
 * no client time is accepted, and BR-05 (no check-out without a check-in) is
 * enforced by the attendance service against the stored record.
 *
 * @see BR-04, BR-05, BR-07 · PRD §9.9.2, §9.9.4 · CONSTITUTION Art. 11
 */
final class CheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof Session
            && $this->user() !== null
            && $this->user()->can('checkOut', [Attendance::class, $session]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'at' => ['prohibited'],
            'time' => ['prohibited'],
            'timestamp' => ['prohibited'],
            'client_time' => ['prohibited'],
            'checked_out_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'at.prohibited' => __('attendance.errors.client_time_rejected'),
            'time.prohibited' => __('attendance.errors.client_time_rejected'),
            'timestamp.prohibited' => __('attendance.errors.client_time_rejected'),
            'client_time.prohibited' => __('attendance.errors.client_time_rejected'),
            'checked_out_at.prohibited' => __('attendance.errors.client_time_rejected'),
        ];
    }

    public function trainingSession(): Session
    {
        /** @var Session $session */
        $session = $this->route('session');

        return $session;
    }
}
