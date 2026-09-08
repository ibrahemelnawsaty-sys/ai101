<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Http\Requests\Concerns\ProfileFieldRules;
use App\Http\Requests\Concerns\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing one's own profile. The name and phone rules are the registration
 * rules, unchanged — a field that could not be entered at sign-up must not
 * become enterable afterwards.
 *
 * The email address is not editable here: changing it re-opens verification and
 * is handled by its own flow.
 *
 * @see BR-22, BR-33 · PRD §9.2.1, §9.4 · CONSTITUTION Art. 5
 */
final class UpdateProfileRequest extends FormRequest
{
    use ProfileFieldRules;
    use UploadRules;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('updateOwnProfile', $user);
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
        $userId = $this->user()?->getKey();

        $rules = [
            'phone' => array_merge(
                $this->phoneRules(),
                [Rule::unique('profiles', 'phone')->ignore($userId, 'user_id')],
            ),
            'avatar' => array_merge(['nullable'], $this->fileRules(2048)),
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('validation.custom.phone.format'),
            'phone.unique' => __('validation.custom.phone.taken'),
        ];
    }

    /**
     * Validated payload rewritten with `profiles` column names.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return $this->toProfileColumns($this->validated());
    }
}
