<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\FinalProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Making one language of the guide available for publishing, or withdrawing it
 * (D-127). The general supervisor only; withdrawing also takes the language
 * down if it was published.
 *
 * @see D-127 · CONSTITUTION Art. 5, Art. 8
 */
final class SetFinalProjectGuideAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof FinalProject && $user !== null && $user->can('update', $project);
    }

    /**
     * The `boolean` rule admits 0, 1, true and false alone; nothing is
     * normalised before it reads the value.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['available' => ['required', 'boolean']];
    }

    public function available(): bool
    {
        return $this->boolean('available');
    }
}
