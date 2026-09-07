<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for another activation link. Like recovery, the reply never reveals
 * whether the address exists or is already verified (BR-30).
 *
 * @see BR-30 · PRD §9.2.3, §9.3.1 · CONSTITUTION Art. 24
 */
final class ResendVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Session key holding the address of a sign-in that met an unverified account. */
    public const PENDING_EMAIL_KEY = 'auth.pending_email';

    /**
     * The "resend the link" button on the sign-in screen carries no address —
     * asking for one there would turn the button into an address oracle. The
     * address is taken from the sign-in attempt that produced the notice, which
     * only happens after a *correct* password (BR-30).
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (! is_string($email) || trim($email) === '') {
            $email = $this->hasSession() ? $this->session()->get(self::PENDING_EMAIL_KEY) : null;
        }

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

    public function throttleKey(): string
    {
        return 'verify-resend|'.mb_strtolower((string) $this->input('email'));
    }
}
