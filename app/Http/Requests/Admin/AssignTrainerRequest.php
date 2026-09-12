<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Cohort;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigning a trainer to a cohort (PRD §4.2).
 *
 * The assignment is the permission — `cohort.scope` and every policy read the
 * enrolment row this creates — so the account being assigned must genuinely be
 * a trainer or an administrator. A participant cannot be promoted sideways by
 * being attached to a cohort (BR-23).
 *
 * @see BR-22, BR-23, BR-33 · PRD §4.2 · CONSTITUTION Art. 22
 */
final class AssignTrainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cohort = $this->route('cohort');
        $user = $this->user();

        return $cohort instanceof Cohort && $user !== null && $user->can('assignTrainer', $cohort);
    }

    /**
     * Accounts that may hold a trainer enrolment (BR-23). One list, read by the
     * rule and by the lookup, so the two cannot disagree about who qualifies.
     */
    private const ASSIGNABLE_ROLES = [UserRole::Trainer, UserRole::Admin];

    /**
     * The cohorts screen asks for the trainer's e-mail address — the one thing
     * an administrator knows about a colleague. This request validated a
     * `user_id` the form never sent, so every attempt failed on a field that
     * does not exist, the error was keyed where nothing rendered it, and the
     * page reloaded in silence. No trainer could be given a cohort from the
     * panel, and under BR-23 that enrolment IS their permission (D-69).
     */
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
        return ['email.exists' => (string) __('admin.cohorts.trainer_not_found')];
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = $this->route('cohort');

        return $cohort;
    }

    public function trainer(): User
    {
        /** @var User $trainer */
        $trainer = User::query()
            ->where('email', (string) $this->validated('email'))
            ->whereIn('role', self::assignableRoles())
            ->sole();

        return $trainer;
    }

    /**
     * @return list<string>
     */
    private static function assignableRoles(): array
    {
        return array_map(static fn (UserRole $role): string => $role->value, self::ASSIGNABLE_ROLES);
    }
}
