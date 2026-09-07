<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issuing a certificate.
 *
 * Both conditions of BR-26 — attendance rate and final score — are checked by
 * CertificateEligibility, not here. This class only carries the override: an
 * administrator may issue against the rules, but only with a written reason
 * that goes into the audit trail (PROJECT-CONTRACT §8).
 *
 * @see BR-25, BR-26, BR-33 · PRD §9.17 · CONSTITUTION Art. 8, Art. 22
 */
final class IssueCertificateRequest extends FormRequest
{
    /** Minimum length of the override reason, in characters. */
    public const MIN_REASON_LENGTH = 10;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $this->boolean('override')
            ? $user->can('issueWithOverride', Certificate::class)
            : $user->can('issue', Certificate::class);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('override_reason');

        $this->merge([
            'override' => $this->boolean('override'),
            'override_reason' => is_string($reason) ? trim($reason) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'uuid', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'cohort_id' => ['required', 'string', 'uuid', Rule::exists('cohorts', 'id')->whereNull('deleted_at')],
            'override' => ['required', 'boolean'],
            'override_reason' => ['required_if:override,true', 'nullable', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000'],
        ];
    }

    public function holder(): User
    {
        /** @var User $holder */
        $holder = User::query()->findOrFail($this->validated('user_id'));

        return $holder;
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = Cohort::query()->findOrFail($this->validated('cohort_id'));

        return $cohort;
    }

    public function isOverride(): bool
    {
        return (bool) $this->validated('override');
    }

    public function overrideReason(): ?string
    {
        $reason = $this->validated('override_reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
