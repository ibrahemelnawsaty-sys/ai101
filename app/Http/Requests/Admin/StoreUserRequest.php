<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Concerns\ProfileFieldRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An administrator creating an account by hand.
 *
 * The field rules are the registration rules, unchanged: an account that could
 * not be created through the public form must not become creatable through the
 * admin panel either (PRD §9.2.1).
 *
 * @see BR-22, BR-32, BR-33 · PRD §4.2, §9.2.1 · CONSTITUTION Art. 5
 */
final class StoreUserRequest extends FormRequest
{
    use ProfileFieldRules;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ($this->arabicNameFields() as $field) {
            $clean[$field] = $this->tidy($this->input($field));
        }

        foreach ($this->latinNameFields() as $field) {
            $value = $this->tidy($this->input($field));
            $clean[$field] = is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : null;
        }

        $email = $this->tidy($this->input('email'));
        $clean['email'] = is_string($email) ? mb_strtolower($email) : null;
        $clean['phone'] = $this->canonicalPhone($this->input('phone'));

        $this->merge(array_filter($clean, static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'phone' => array_merge($this->phoneRules(), [Rule::unique('profiles', 'phone')]),
            'gender' => ['required', Rule::enum(Gender::class)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'password' => ['required', 'string', 'confirmed', $this->passwordRules()],
        ];

        foreach ($this->arabicNameFields() as $field) {
            $rules[$field] = $this->arabicNameRules();
        }

        foreach ($this->latinNameFields() as $field) {
            $rules[$field] = $this->latinNameRules();
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return $this->toProfileColumns($this->validated());
    }
}
