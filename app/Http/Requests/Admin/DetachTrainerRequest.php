<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cohort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a trainer from a cohort — which ends their reach into it (BR-23).
 *
 * The endpoint authorised inside the method and took no request of its own:
 * two legs of the three Article 5 requires. It carries no fields; the cohort
 * and the trainer in the path are the whole instruction (D-69).
 *
 * @see BR-23 · CONSTITUTION Art. 5 · D-69
 */
final class DetachTrainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cohort = $this->route('cohort');
        $user = $this->user();

        return $cohort instanceof Cohort && $user !== null && $user->can('assignTrainer', $cohort);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
