<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

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
 * IT ASKS FOR A NAME AND AN ADDRESS (D-85).
 *
 * It used to demand all eleven registration facts before an invitation could
 * be sent: four Arabic name parts, four Latin ones, the mobile number and the
 * gender. An administrator holding a list of names and addresses could not
 * answer it — and every one of those facts is known best by the person
 * themself, who now fills them in behind their invitation link. Only the first
 * part of the Arabic name is required; a two- or three-part name is a name.
 *
 * TWO FIELDS CHANGED WHEN REGISTRATION CLOSED (D-63).
 *
 * `cohort_id` is new and required for a participant. Without it this form
 * produced an account belonging to NO cohort: no assignments, no sessions, no
 * card, no path to a certificate -- and no screen anywhere could put it in one,
 * because the only code in the whole application that creates an `Enrollment`
 * is the e-mail-verification controller, on the self-registration path the
 * administrator is not using. `athar:make-user` even printed "create that from
 * the admin panel", naming a feature that did not exist; it now refuses the
 * participant role and points here instead (D-69).
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

        $email = $this->tidy($this->input('email'));
        $clean['email'] = is_string($email) ? mb_strtolower($email) : null;

        // An untouched optional field posts an empty string, and `nullable`
        // spares only a null — so an empty one is dropped rather than sent on
        // to fail `min:2`.
        $this->merge(array_filter(
            $clean,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                // Deleted accounts included: `users.email` is unique across
                // them in the database, so letting their address through here
                // ended in an insert error — a 500 — instead of a message (D-84).
                Rule::unique('users', 'email'),
            ],
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

        // The first part is the one every screen names a person by. The rest
        // are offered and never demanded — and the mobile number, the gender
        // and the Latin name are asked for on the invitation screen, by the
        // person who knows them (D-85).
        $rules['first_name_ar'] = $this->arabicNameRules();

        foreach (['father_name_ar', 'grandfather_name_ar', 'family_name_ar'] as $field) {
            $rules[$field] = $this->optionalArabicNameRules();
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
     * The cohort itself, for the letter and the audit entry.
     *
     * Not locked. It used to be, and the docblock said the lock prevented
     * overselling — but this runs in its own SELECT before the inviter's
     * transaction opens, so the lock ended with the statement. Seating and its
     * lock live in AccountInviter::seat(), inside the transaction (D-69).
     */
    public function cohort(): ?Cohort
    {
        $id = $this->cohortId();

        if ($id === null) {
            return null;
        }

        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()->with('program')->whereKey($id)->first();

        return $cohort;
    }
}
