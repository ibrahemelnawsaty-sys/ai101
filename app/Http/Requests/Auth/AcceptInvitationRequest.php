<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\EmailTokenType;
use App\Enums\Gender;
use App\Http\Controllers\Auth\Concerns\IssuesEmailTokens;
use App\Http\Requests\Concerns\ProfileFieldRules;
use App\Models\EmailToken;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The invited person filling in what the invitation did not know.
 *
 * THE ADDRESS IS NOT A FIELD. It is not validated, not read and not written:
 * it belongs to the token, and the token belongs to the account the
 * administrator invited. A posted `email` is ignored entirely — which is the
 * only way to promise that the address cannot be changed here (D-85).
 *
 * EVERYTHING ELSE IS THEIRS. The name the administrator typed is offered back
 * and may be corrected; the parts nobody filled may be filled now or left
 * empty; the mobile number and the gender are asked for here because this is
 * the first moment the person themself is present to answer.
 *
 * The rules are `ProfileFieldRules` — registration's own — so a value this
 * accepts is a value the public form would have accepted.
 *
 * @see PRD §9.2.1, §9.4 · BR-29, BR-30 · CONSTITUTION Art. 5 · D-85
 */
final class AcceptInvitationRequest extends FormRequest
{
    use IssuesEmailTokens;
    use ProfileFieldRules;

    private ?EmailToken $record = null;

    private bool $resolved = false;

    public function authorize(): bool
    {
        // A signed-in visitor is not the person opening an invitation, and the
        // route is guest-only in any case. The token is what authorises this.
        return $this->user() === null;
    }

    /**
     * The link carries the token in its path; the form posts it too. The path
     * wins, so a page cannot be made to spend another account's token by
     * editing a hidden field.
     */
    protected function prepareForValidation(): void
    {
        $token = $this->route('token');

        if (is_string($token) && $token !== '') {
            $this->merge(['token' => $token]);
        }

        $clean = [];

        foreach ($this->arabicNameFields() as $field) {
            $clean[$field] = $this->tidy($this->input($field));
        }

        foreach ($this->latinNameFields() as $field) {
            $value = $this->tidy($this->input($field));
            $clean[$field] = is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : null;
        }

        $clean['phone'] = $this->canonicalPhone($this->input('phone'));

        // An untouched optional field arrives as an empty string, and `nullable`
        // only spares a null: without this, leaving the grandfather's name blank
        // failed `min:2` — the very thing this screen is meant to allow.
        $this->merge(array_map(
            static fn (mixed $value): mixed => $value === '' ? null : $value,
            $clean,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'token' => ['required', 'string', 'max:255'],
            // Their own row is excluded: the invitation created the profile
            // with no number, and a retried submission must not collide with
            // whatever it wrote the first time.
            'phone' => array_merge($this->phoneRules(), [
                Rule::unique('profiles', 'phone')->ignore($this->profileId()),
            ]),
            'gender' => ['required', Rule::enum(Gender::class)],
            'password' => ['required', 'string', 'confirmed', $this->passwordRules()],
            'first_name_ar' => $this->arabicNameRules(),
        ];

        foreach (['father_name_ar', 'grandfather_name_ar', 'family_name_ar'] as $field) {
            $rules[$field] = $this->optionalArabicNameRules();
        }

        // The Latin name is what the certificate and the digital card print in
        // English. It is asked for, never demanded: a trainee who leaves it
        // empty still finishes the programme (D-85).
        foreach ($this->latinNameFields() as $field) {
            $rules[$field] = $this->optionalLatinNameRules();
        }

        return $rules;
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
        return [
            'password.confirmed' => __('validation.custom.password.mismatch'),
        ];
    }

    /** The account this link belongs to, or null when the link is spent, expired or unknown. */
    public function invitee(): ?User
    {
        $user = $this->tokenRecord()?->user;

        return $user instanceof User ? $user : null;
    }

    /** The token row itself, so the controller can spend exactly the one it read. */
    public function tokenRecord(): ?EmailToken
    {
        if (! $this->resolved) {
            $this->resolved = true;

            $token = $this->route('token');
            $this->record = is_string($token) && $token !== ''
                ? $this->findUsableToken($token, EmailTokenType::Invite)
                : null;
        }

        return $this->record;
    }

    /**
     * The profile columns this person filled in, mapped to `profiles` names.
     *
     * Only what they actually wrote: an empty optional field is left absent,
     * so the column stays NULL rather than holding an empty string.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $given = array_filter(
            $validated,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        unset($given['token'], $given['password']);

        return $this->toProfileColumns($given);
    }

    private function profileId(): ?string
    {
        $user = $this->invitee();

        if (! $user instanceof User) {
            return null;
        }

        $id = Profile::query()->where('user_id', $user->getKey())->value('id');

        return is_string($id) ? $id : null;
    }
}
