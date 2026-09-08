<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Gender;
use App\Http\Requests\Concerns\ProfileFieldRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An administrator editing somebody else's personal details.
 *
 * The role is deliberately absent here: changing a role is a separate,
 * separately audited endpoint (PRD §4.2) so that an ordinary detail edit can
 * never carry a privilege escalation in the same payload.
 *
 * @see BR-22, BR-33 · PRD §4.2, §4.5.1 · CONSTITUTION Art. 5
 */
final class UpdateUserRequest extends FormRequest
{
    use ProfileFieldRules;

    public function authorize(): bool
    {
        $subject = $this->route('user');
        $user = $this->user();

        return $subject instanceof User && $user !== null && $user->can('update', $subject);
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ($this->arabicNameFields() as $field) {
            if ($this->has($field)) {
                $clean[$field] = $this->tidy($this->input($field));
            }
        }

        foreach ($this->latinNameFields() as $field) {
            if ($this->has($field)) {
                $value = $this->tidy($this->input($field));
                $clean[$field] = is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : null;
            }
        }

        if ($this->has('phone')) {
            $clean['phone'] = $this->canonicalPhone($this->input('phone'));
        }

        $this->merge(array_filter($clean, static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subject = $this->route('user');
        $subjectId = $subject instanceof User ? $subject->getKey() : null;

        $rules = [
            'phone' => array_merge(
                $this->phoneRules(),
                [Rule::unique('profiles', 'phone')->ignore($subjectId, 'user_id')],
            ),
            'gender' => ['required', Rule::enum(Gender::class)],
            'city' => ['nullable', 'string', 'max:80'],
            'education_level' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:500'],
        ];

        foreach ($this->arabicNameFields() as $field) {
            $rules[$field] = $this->arabicNameRules();
        }

        foreach ($this->latinNameFields() as $field) {
            $rules[$field] = $this->latinNameRules();
        }

        return $rules;
    }

    public function subject(): User
    {
        /** @var User $subject */
        $subject = $this->route('user');

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return $this->toProfileColumns($this->validated());
    }
}
