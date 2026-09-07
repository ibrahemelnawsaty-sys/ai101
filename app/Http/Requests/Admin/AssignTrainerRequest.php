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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required', 'string', 'uuid',
                Rule::exists('users', 'id')
                    ->whereNull('deleted_at')
                    ->whereIn('role', [UserRole::Trainer->value, UserRole::Admin->value]),
            ],
        ];
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
        $trainer = User::query()->findOrFail($this->validated('user_id'));

        return $trainer;
    }
}
