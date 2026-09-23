<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Models\Attendance;
use App\Models\Session;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-check-in by scanning the coordinator's QR code (D-106).
 *
 * The `signed` middleware has already proven this exact URL was minted by the
 * server and has not expired; this request adds the same enrolment check
 * `CheckInRequest` uses for the manual button. There is no body to validate —
 * the link carries every parameter — so `rules()` stays empty, but the
 * FormRequest still exists to keep authorisation in one place with every
 * other attendance write (Article 5).
 *
 * @see D-106 · BR-01, BR-02, BR-03, BR-06 · CONSTITUTION Art. 5, Art. 11
 */
final class SelfCheckInRequest extends FormRequest
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
        return [];
    }

    public function trainingSession(): Session
    {
        /** @var Session $session */
        $session = $this->route('session');

        return $session;
    }
}
