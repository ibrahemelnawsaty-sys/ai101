<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Models\AttendanceExceptionRequest;
use App\Services\Attendance\AttendanceExceptionRequester;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving or rejecting a participant's excuse request (D-106) — the same
 * shape as Admin\RegistrationDecisionRequest: a rejection must say why, an
 * approval carries no payload, and the route name decides the verdict.
 *
 * @see D-106
 */
final class DecideAttendanceExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('exceptionRequest');
        $user = $this->user();

        if (! $request instanceof AttendanceExceptionRequest || $user === null) {
            return false;
        }

        return $user->can('decide', $request);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('decision_reason');

        if (is_string($reason)) {
            $this->merge(['decision_reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->rejects()
            ? ['decision_reason' => ['required', 'string', 'min:'.AttendanceExceptionRequester::MIN_REASON_LENGTH, 'max:1000']]
            : [];
    }

    /** The route name decides the verdict, never a hidden field in the form. */
    public function rejects(): bool
    {
        return str_ends_with((string) $this->route()?->getName(), '.reject');
    }

    public function reason(): ?string
    {
        $reason = $this->validated('decision_reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
