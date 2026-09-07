<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issuing a certificate to someone who does not meet the conditions.
 *
 * BR-26 says the two conditions are applied together and neither compensates
 * for the other. An administrator may still decide to issue — but only through
 * this endpoint, only with a written reason, and the trail records the act as
 * an override rather than as an ordinary issue (PROJECT-CONTRACT §8).
 *
 * The holder comes from the path, the cohort from the form, and the reason is
 * mandatory. There is no way to reach this class without all three.
 *
 * @see BR-25, BR-26, BR-33 · PRD §9.17 · CONSTITUTION Art. 8
 */
final class OverrideCertificateRequest extends FormRequest
{
    public const MIN_REASON_LENGTH = 10;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('issueWithOverride', Certificate::class);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('override_reason');

        if (is_string($reason)) {
            $this->merge(['override_reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cohort_id' => ['required', 'string', 'uuid', Rule::exists('cohorts', 'id')->whereNull('deleted_at')],
            'override_reason' => ['required', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000'],
        ];
    }

    public function holder(): User
    {
        /** @var User $holder */
        $holder = $this->route('user');

        return $holder;
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = Cohort::query()->findOrFail($this->validated('cohort_id'));

        return $cohort;
    }

    public function reason(): string
    {
        return (string) $this->validated('override_reason');
    }
}
