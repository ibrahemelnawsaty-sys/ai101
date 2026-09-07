<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Activating or suspending an account from the admin console.
 *
 * `deleted` is deliberately not an option here: removing an account is its own
 * endpoint, its own confirmation and its own audit entry. A status toggle must
 * not be able to delete somebody by posting a different value.
 *
 * Whether the platform would be left without an active administrator is decided
 * by the policy and re-checked in the controller (BR-32).
 *
 * @see BR-32, BR-33 · PRD §4.3, §4.4 · CONSTITUTION Art. 22
 */
final class ChangeUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('user');
        $user = $this->user();

        if (! $subject instanceof User || $user === null) {
            return false;
        }

        return $this->target() === UserStatus::Active
            ? $user->can('restore', $subject)
            : $user->can('suspend', $subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([UserStatus::Active->value, UserStatus::Suspended->value])],
        ];
    }

    public function subject(): User
    {
        /** @var User $subject */
        $subject = $this->route('user');

        return $subject;
    }

    /** The requested status, defaulting to suspension so a malformed post never activates. */
    public function target(): UserStatus
    {
        $value = $this->input('status');

        return is_string($value)
            ? (UserStatus::tryFrom($value) ?? UserStatus::Suspended)
            : UserStatus::Suspended;
    }
}
