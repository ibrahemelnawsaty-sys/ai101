<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\FinalProjectField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing one field from the hand-in form (D-121).
 *
 * Nothing a participant handed in is touched: every hand-in carries its own
 * copy of the fields it answered (`project_submissions.answers`), so a removed
 * field only stops being asked for. The request carries no input beyond the
 * route; it exists so the removal passes a policy like every other write
 * (CONSTITUTION Art. 5), and never from a preview (BR-33).
 *
 * @see BR-19, BR-31, BR-33 · PRD §9.14.2 · D-121 · CONSTITUTION Art. 5
 */
final class DeleteFinalProjectFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $field = $this->route('field');

        return $user !== null && $field instanceof FinalProjectField && $user->can('delete', $field);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
