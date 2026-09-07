<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Password-recovery request.
 *
 * Deliberately free of any `exists` rule: asking the database whether the
 * address is registered and reporting the answer is exactly the account
 * enumeration BR-30 forbids. The controller always replies with the same
 * sentence, whether or not the address is known.
 *
 * @see BR-30 · PRD §9.3.3 · CONSTITUTION Art. 24
 */
final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /** Throttle key: 3 requests per address per hour (PRD §12.4). */
    public function throttleKey(): string
    {
        return 'password|'.mb_strtolower((string) $this->input('email'));
    }
}
