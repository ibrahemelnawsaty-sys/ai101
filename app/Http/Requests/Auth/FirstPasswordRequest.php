<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ProfileFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Replacing the temporary password that arrived by e-mail.
 *
 * THE RULES ARE `ChangePasswordRequest`'S, WHOLE.
 * Not "the same idea" — the same list. Two of them are the difference between a
 * working account and a locked one:
 *
 *   · `confirmed`. The screen renders a confirmation field; without this rule
 *     nothing compares the two, and a typo silently becomes the trainee's real
 *     password. They are then locked out of the account they received five
 *     minutes ago, holding a temporary password that no longer works.
 *   · the blacklist check in `withValidator()`. This is the one screen in the
 *     platform where somebody chooses a password under pressure, in a room,
 *     with a trainer waiting — which is exactly when `Password123!` gets typed.
 *
 * `current_password` is the temporary one. It is required even though the
 * trainee has just signed in with it: a session left open on a shared machine
 * must not be enough to take the account over.
 *
 * @see BR-29 · PRD §9.3.3 · CONSTITUTION Art. 5, Art. 24 · D-63
 */
final class FirstPasswordRequest extends FormRequest
{
    use ProfileFieldRules;

    public function authorize(): bool
    {
        $user = $this->user();

        // The gate is the flag itself: this endpoint means nothing for an
        // account that is not holding a temporary password, and leaving it open
        // would give a signed-in user a second password form with different
        // rules from the one on their profile.
        return $user !== null
            && $user->can('updateOwnProfile', $user)
            && (bool) $user->getAttribute('must_change_password') === true;
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
