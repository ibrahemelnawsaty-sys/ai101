<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cohort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a coordinator from a cohort — mirrors DetachTrainerRequest.
 *
 * @see BR-23 · CONSTITUTION Art. 5 · D-105
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
}
