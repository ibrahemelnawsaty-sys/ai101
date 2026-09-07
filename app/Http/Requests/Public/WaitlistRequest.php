<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Joining the waiting list when the open cohort is full or registration is
 * closed (PRD §9.1.3).
 *
 * The reply is identical whether or not the address is already on the list, for
 * the same reason the recovery reply is identical: a public form must not become
 * an address oracle (BR-30).
 *
 * @see BR-30, BR-31 · PRD §9.1.3 · CONSTITUTION Art. 24
 */
final class WaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }
}
