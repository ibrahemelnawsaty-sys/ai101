<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Cohort;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigning a coordinator to a cohort — mirrors AssignTrainerRequest exactly,
 * for the one role difference: the account must genuinely be a coordinator or
 * an administrator, never a participant promoted sideways (BR-23).
 *
 * @see BR-22, BR-23, BR-33 · PRD §4.2 · CONSTITUTION Art. 22 · D-105
 */
final class AssignCoordinatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cohort = $this->route('cohort');
        $user = $this->user();

        return $cohort instanceof Cohort && $user !== null && $user->can('assignCoordinator', $cohort);
    }

    /**
     * Accounts that may hold a coordinator enrolment (BR-23).
     */
    private const ASSIGNABLE_ROLES = [UserRole::Coordinator, UserRole::Admin];

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
            'email' => [
                'bail', 'required', 'string', 'email:rfc', 'max:190',
                Rule::exists('users', 'email')
                    ->whereNull('deleted_at')
                    ->whereIn('role', self::assignableRoles()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.exists' => (string) __('admin.cohorts.coordinator_not_found')];
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = $this->route('cohort');

        return $cohort;
    }

    public function coordinator(): User
    {
        /** @var User $coordinator */
        $coordinator = User::query()
            ->where('email', (string) $this->validated('email'))
            ->whereIn('role', self::assignableRoles())
            ->sole();

        return $coordinator;
    }

    /**
     * @return list<string>
     */
    private static function assignableRoles(): array
    {
        return array_map(static fn (UserRole $role): string => $role->value, self::ASSIGNABLE_ROLES);
    }
}
