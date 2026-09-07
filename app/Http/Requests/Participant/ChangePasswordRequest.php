<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Http\Requests\Concerns\ProfileFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Changing one's own password from inside the account.
 *
 * The current password is required, and a successful change invalidates every
 * other active session for that user (BR-29) — the controller performs that,
 * because it is a consequence of the change, not a validation rule.
 *
 * @see BR-29, BR-33 · PRD §9.3.3, §9.4 · CONSTITUTION Art. 24
 */
final class ChangePasswordRequest extends FormRequest
{
    use ProfileFieldRules;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('updateOwnProfile', $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', $this->passwordRules()],
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
            'current_password.current_password' => __('validation.custom.password.current_wrong'),
            'password.confirmed' => __('validation.custom.password.mismatch'),
            'password.different' => __('validation.custom.password.reused'),
        ];
    }
}
