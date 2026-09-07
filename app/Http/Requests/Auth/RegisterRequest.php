<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\Gender;
use App\Http\Requests\Concerns\ProfileFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Registration form, all three steps, validated on the server.
 * The browser repeats these checks for comfort; this class is the one that
 * decides (CONSTITUTION Art. 5).
 *
 * @see PRD §9.2.1, §9.2.2, §9.2.3 · CONSTITUTION Art. 5, Art. 24
 */
final class RegisterRequest extends FormRequest
{
    use ProfileFieldRules;

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ($this->arabicNameFields() as $field) {
            $clean[$field] = $this->tidy($this->input($field));
        }

        foreach ($this->latinNameFields() as $field) {
            $value = $this->tidy($this->input($field));
            // §9.2.1 asks for the first letter of each Latin name to be capitalised.
            $clean[$field] = is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : null;
        }

        $email = $this->tidy($this->input('email'));
        $confirmation = $this->tidy($this->input('email_confirmation'));

        $clean['email'] = is_string($email) ? mb_strtolower($email) : null;
        $clean['email_confirmation'] = is_string($confirmation) ? mb_strtolower($confirmation) : null;
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
                'required', 'string', 'email:rfc,dns', 'max:255', 'confirmed',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'phone' => array_merge($this->phoneRules(), [
                Rule::unique('profiles', 'phone'),
            ]),
            'gender' => ['required', Rule::enum(Gender::class)],
            'password' => ['required', 'string', 'confirmed', $this->passwordRules()],
            'terms_accepted' => ['accepted'],
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
     * The validated payload rewritten with `profiles` column names, ready to
     * be persisted without a controller having to know the mapping.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return $this->toProfileColumns($this->validated());
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
        $messages = [
            'email.unique' => __('validation.custom.email.taken'),
            'email.email' => __('validation.custom.email.format'),
            'email.confirmed' => __('validation.custom.email.mismatch'),
            'phone.regex' => __('validation.custom.phone.format'),
            'phone.unique' => __('validation.custom.phone.taken'),
            'gender.required' => __('validation.custom.gender.required'),
            'password.confirmed' => __('validation.custom.password.mismatch'),
            'terms_accepted.accepted' => __('validation.custom.terms.required'),
        ];

        foreach ($this->arabicNameFields() as $field) {
            $messages[$field.'.regex'] = __('validation.custom.names.arabic', ['field' => __('validation.attributes.'.$field)]);
            $messages[$field.'.required'] = __('validation.custom.names.arabic', ['field' => __('validation.attributes.'.$field)]);
            $messages[$field.'.min'] = __('validation.custom.names.length');
            $messages[$field.'.max'] = __('validation.custom.names.length');
        }

        foreach ($this->latinNameFields() as $field) {
            $messages[$field.'.regex'] = __('validation.custom.names.latin', ['field' => __('validation.attributes.'.$field)]);
            $messages[$field.'.required'] = __('validation.custom.names.latin', ['field' => __('validation.attributes.'.$field)]);
            $messages[$field.'.min'] = __('validation.custom.names.length');
            $messages[$field.'.max'] = __('validation.custom.names.length');
        }

        return $messages;
    }
}
