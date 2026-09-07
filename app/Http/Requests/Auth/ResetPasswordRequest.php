<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ProfileFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Setting a new password from a recovery link. Same strength rules as
 * registration; the link itself is single-use and expires after 30 minutes,
 * which the service checks, not this class.
 *
 * @see BR-29, BR-30 · PRD §9.3.3 · CONSTITUTION Art. 24
 */
final class ResetPasswordRequest extends FormRequest
{
    use ProfileFieldRules;

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /**
     * The link carries the token in its path; a form may also post it. The
     * path wins, so a page cannot be made to spend a different account's token
     * by editing a hidden field.
     */
    protected function prepareForValidation(): void
    {
        $token = $this->route('token');

        if (is_string($token) && $token !== '') {
            $this->merge(['token' => $token]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', $this->passwordRules()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->isBlacklistedPassword($this->input('password'))) {
                $validator->errors()->add('password', __('validation.custom.password.common'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => __('validation.custom.password.mismatch'),
        ];
    }
}
