<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cohort;
use App\Models\User;
use App\Services\Cohorts\PrimaryCoordinator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Removing a coordinator from a cohort — mirrors DetachTrainerRequest, with
 * the owner's D-124 rule on top: no cohort is left without a coordinator, and
 * the primary coordinator leaves only once no choice remains (one coordinator
 * left, who is then primary on their own). Both refusals are validation
 * errors on the page, telling the supervisor what to do first.
 *
 * @see BR-23 · CONSTITUTION Art. 5 · D-105, D-124
 */
final class DetachCoordinatorRequest extends FormRequest
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
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cohort = $this->route('cohort');
            $leaving = $this->route('coordinator');

            if (! $cohort instanceof Cohort || ! $leaving instanceof User) {
                return;
            }

            $primary = app(PrimaryCoordinator::class);
            $leavingId = (string) $leaving->getKey();
            $coordinators = $primary->coordinatorIds($cohort);

            if (! in_array($leavingId, $coordinators, true)) {
                return;
            }

            $remaining = count($coordinators) - 1;

            if ($remaining === 0) {
                $validator->errors()->add('coordinator', (string) __('admin.cohorts.detach_last_coordinator'));

                return;
            }

            if ($remaining > 1 && $primary->idOf($cohort) === $leavingId) {
                $validator->errors()->add('coordinator', (string) __('admin.cohorts.detach_primary_first'));
            }
        });
    }
}
