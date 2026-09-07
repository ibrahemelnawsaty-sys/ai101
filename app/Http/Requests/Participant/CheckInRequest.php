<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Models\Attendance;
use App\Models\Session;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording attendance for a session.
 *
 * The request carries no time and may not carry one: the moment of the
 * check-in is the server's clock and nothing else (BR-07). Any attempt to send
 * a timestamp is rejected outright rather than ignored, so a client that tries
 * gets an error instead of a silent success on the wrong terms.
 *
 * Whether the window is open is decided by AttendanceWindow; whether this
 * person belongs to this session is decided by AttendancePolicy.
 *
 * @see BR-01, BR-02, BR-03, BR-06, BR-07 · PRD §9.9.2, §9.9.4 · CONSTITUTION Art. 11
 */
final class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof Session
            && $this->user() !== null
            && $this->user()->can('checkIn', [Attendance::class, $session]);
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
            'checked_in_at' => ['prohibited'],
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
            'checked_in_at.prohibited' => __('attendance.errors.client_time_rejected'),
        ];
    }

    public function trainingSession(): Session
    {
        /** @var Session $session */
        $session = $this->route('session');

        return $session;
    }
}
