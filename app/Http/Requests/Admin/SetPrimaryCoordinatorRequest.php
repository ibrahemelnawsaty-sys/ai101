<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cohort;
use App\Services\Cohorts\PrimaryCoordinator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Choosing a cohort's primary coordinator (D-124): the one a support ticket
 * reaches first. The general supervisor chooses among the cohort's active
 * coordinators — nobody else can be named, whatever id the form carries.
 *
 * @see D-124 · D-105 · CONSTITUTION Art. 5, Art. 22
 */
final class SetPrimaryCoordinatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cohort = $this->route('cohort');
        $user = $this->user();

        return $cohort instanceof Cohort && $user !== null && $user->can('assignCoordinator', $cohort);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['coordinator_id' => ['required', 'string', 'uuid']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! app(PrimaryCoordinator::class)->isCoordinatorOf($this->cohort(), $this->coordinatorId())) {
                $validator->errors()->add('coordinator_id', (string) __('admin.cohorts.primary_not_coordinator'));
            }
        });
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = $this->route('cohort');

        return $cohort;
    }

    public function coordinatorId(): string
    {
        return (string) $this->input('coordinator_id');
    }
}
