<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Models\Session;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling a session. A reason is mandatory: the cohort is told why and the
 * trail keeps the sentence (PRD §9.8.2).
 *
 * @see BR-23, BR-27 · PRD §9.8.2 · CONSTITUTION Art. 8
 */
final class CancelSessionRequest extends FormRequest
{
    public const MIN_REASON_LENGTH = 10;

    public function authorize(): bool
    {
        $session = $this->route('session');
        $user = $this->user();

        return $session instanceof Session && $user !== null && $user->can('cancel', $session);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('cancel_reason');

        if (is_string($reason)) {
            $this->merge(['cancel_reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000'],
        ];
    }

    public function trainingSession(): Session
    {
        /** @var Session $session */
        $session = $this->route('session');

        return $session;
    }

    public function reason(): string
    {
        return (string) $this->validated('cancel_reason');
    }
}
