<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sign-in credentials.
 *
 * Nothing here may hint at whether an address exists: validation failures are
 * shape failures only, and the single "wrong email or password" answer is
 * produced by the controller after the attempt (BR-30).
 *
 * @see BR-30 · PRD §9.3.1 · CONSTITUTION Art. 24
 */
final class LoginRequest extends FormRequest
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
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function remembers(): bool
    {
        return $this->boolean('remember');
    }

    /** Throttle key: per account, not per IP, exactly as PRD §12.4 specifies. */
    public function throttleKey(): string
    {
        return 'login|'.mb_strtolower((string) $this->input('email'));
    }
}
