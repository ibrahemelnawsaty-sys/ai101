<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Remind who has not submitted" — the request that writes to a cohort.
 *
 * The endpoint took a bare route model and authorised inside the method: two
 * legs of the three Article 5 requires. It carries no fields; the assignment
 * in the path is the whole instruction, and AssignmentPolicy::remind decides
 * whether this person may give it for that assignment's cohort (D-68).
 *
 * @see PRD §9.11.3 · FR-ASGN-30 · BR-23 · CONSTITUTION Art. 5 · D-68
 */
final class RemindAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');
        $user = $this->user();

        return $assignment instanceof Assignment && $user !== null && $user->can('remind', $assignment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
