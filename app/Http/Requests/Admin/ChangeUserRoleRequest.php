<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing a user's global role — its own endpoint so the change is auditable
 * on its own and can never ride along inside a details edit.
 *
 * The policy refuses a self-change and refuses anything while a preview is
 * running; this class only checks the shape of the payload (PRD §4.2, §4.3).
 *
 * @see BR-22, BR-32, BR-33 · PRD §4.2, §4.3 · CONSTITUTION Art. 22
 */
final class ChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('user');
        $user = $this->user();

        return $subject instanceof User && $user !== null && $user->can('changeRole', $subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(UserRole::class)],

            // Raising an account to administrator is the highest-privilege
            // write on this platform. The screen has always demanded a reason;
            // the server never asked for one, so it never reached audit_logs.
            // Article 8 wants the record to say WHY, not only what changed.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function subject(): User
    {
        /** @var User $subject */
        $subject = $this->route('user');

        return $subject;
    }

    public function role(): UserRole
    {
        return UserRole::from((string) $this->validated('role'));
    }
}
