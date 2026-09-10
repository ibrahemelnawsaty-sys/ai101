<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Concerns\ProfileFieldRules;
use App\Models\Cohort;
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
 * TWO FIELDS CHANGED WHEN REGISTRATION CLOSED (D-63).
 *
 * `cohort_id` is new and required for a participant. Without it this form
 * produced an account belonging to NO cohort: no assignments, no sessions, no
 * card, no path to a certificate -- and no screen anywhere could put it in one,
 * because the only code in the whole application that creates an `Enrollment`
 * is the e-mail-verification controller, on the self-registration path the
 * administrator is not using. `athar:make-user` even prints "create that from
 * the admin panel", naming a feature that did not exist.
 *
 * `password` is GONE. The administrator no longer chooses a trainee's password:
 * the platform generates a temporary one, mails it, and forces a change at the
 * first sign-in. An administrator who knows a trainee's password can sign in as
 * them without leaving the impersonation record BR-34 requires.
 *
 * @see BR-22, BR-32, BR-33, BR-34 · PRD §4.2, §9.2.1 · CONSTITUTION Art. 5 · D-63
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
            // A participant with no cohort is an account that can do nothing.
            // Required only for participants: a trainer is attached to a cohort
            // through AdminCohortController::attachTrainer, and an
            // administrator belongs to none.
            'cohort_id' => [
                Rule::requiredIf(fn (): bool => $this->input('role') === UserRole::Participant->value),
                'nullable',
                'string',
                Rule::exists('cohorts', 'id'),
            ],
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

    /** The cohort to seat this account in, or null for a trainer or admin. */
    public function cohortId(): ?string
    {
        $id = $this->validated('cohort_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The cohort itself, locked for update.
     *
     * Locked because seating increments `cohorts.seats_taken`, and two
     * administrators working the same list at the same moment would otherwise
     * both read the old count and both write old+1 -- overselling a seat, which
     * is the exact failure the verification controller's own seating code takes
     * a lock to prevent.
     */
    public function cohort(): ?Cohort
    {
        $id = $this->cohortId();

        if ($id === null) {
            return null;
        }

        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()->whereKey($id)->lockForUpdate()->first();

        return $cohort;
    }
}
