<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CohortStatus;
use App\Models\Cohort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Opening or closing a cohort's registration, from the registrations screen.
 *
 * The owner put the switch with the general supervisor, beside the requests it
 * lets in (D-117): opening, closing and accepting are one person's decision.
 * It used to be one field of the landing editor's draft, which the system
 * administrator now owns and which no longer carries it.
 *
 * Only a cohort that can still take registrations may have the switch moved:
 * the registration form admits an `open` cohort alone, so a running or a
 * finished cohort would show a switch that changes nothing.
 *
 * @see BR-31 · PRD §9.1.2, §9.2.3, §9.18 · CONSTITUTION Art. 5, Art. 8 · D-117
 */
final class SetRegistrationIntakeRequest extends FormRequest
{
    /** The cohorts whose registration the switch still governs. */
    public const GOVERNED = [CohortStatus::Upcoming, CohortStatus::Open];

    public function authorize(): bool
    {
        $cohort = $this->route('cohort');
        $user = $this->user();

        return $cohort instanceof Cohort && $user !== null && $user->can('manageRegistrations', $cohort);
    }

    /**
     * Nothing is normalised before the rule reads it: `filter_var` turns an
     * empty or missing value into `false`, which is a closed registration. The
     * `boolean` rule admits 0, 1, true and false alone, and refuses the rest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['open' => ['required', 'boolean']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $status = $this->cohort()->getAttribute('status');
            $status = $status instanceof CohortStatus ? $status : CohortStatus::tryFrom((string) $status);

            if (! in_array($status, self::GOVERNED, true)) {
                $validator->errors()->add('open', (string) __('admin.registrations.intake.not_governed'));
            }
        });
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = $this->route('cohort');

        return $cohort;
    }

    public function open(): bool
    {
        return (bool) $this->validated('open');
    }
}
