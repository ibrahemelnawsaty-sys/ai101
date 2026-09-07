<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Soft-deleting an account from the admin console.
 *
 * A written reason is welcome but NOT mandatory. PRD §9.18 makes a reason
 * compulsory in exactly one place - declining a registration request, where the
 * text is sent to the applicant - and BR-10 and BR-14 add attendance edits and
 * grade revisions. Removing an account is not one of them, and the previous
 * `required` rule borrowed both the field name (`reject_reason`) and the
 * message ("the reason reaches the applicant") from the registration screen, so
 * the delete button in admin/users - which posts no reason at all - could never
 * succeed. The trail still records who, what, before, after and the IP without
 * it (art. 8).
 *
 * Whether the platform would be left without an active administrator is decided
 * by the policy and re-checked in the controller before anything is written
 * (BR-32).
 *
 * @see BR-32, BR-33 · PRD §4.3, §4.4, §9.18 · CONSTITUTION Art. 8, Art. 22
 */
final class SuspendUserRequest extends FormRequest
{
    /** Minimum length of the reason, when one is written at all. */
    public const MIN_REASON_LENGTH = 10;

    public function authorize(): bool
    {
        $subject = $this->route('user');
        $user = $this->user();

        if (! $subject instanceof User || $user === null) {
            return false;
        }

        return $this->isMethod('DELETE')
            ? $user->can('delete', $subject)
            : $user->can('suspend', $subject);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $trimmed = trim($reason);

            $this->merge(['reason' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000'],
        ];
    }

    public function subject(): User
    {
        /** @var User $subject */
        $subject = $this->route('user');

        return $subject;
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
