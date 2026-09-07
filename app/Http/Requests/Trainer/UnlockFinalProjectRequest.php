<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Models\FinalProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening the final-project tab for a cohort (BR-15, BR-16).
 *
 * Before this flag is set, no word of the brief may reach a participant page:
 * the controller passes nothing at all, and the policy answers a direct request
 * with 403 rather than with hidden markup.
 *
 * @see BR-15, BR-16, BR-23 · PRD §9.14 · CONSTITUTION Art. 5
 */
final class UnlockFinalProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof FinalProject && $user !== null && $user->can('unlock', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_unlocked' => ['required', 'boolean'],
        ];
    }

    /**
     * The endpoint is named `unlock` and PRD §9.14.1 describes activation only;
     * re-locking is the trainer dashboard's extra toggle, which always sends the
     * flag explicitly. A request that omits the flag therefore means «open it»,
     * never «close it» — reading a missing field as `false` would let the
     * activation endpoint silently do the opposite of its name.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_unlocked' => $this->has('is_unlocked') ? $this->boolean('is_unlocked') : true,
        ]);
    }

    public function project(): FinalProject
    {
        /** @var FinalProject $project */
        $project = $this->route('project');

        return $project;
    }

    public function unlocks(): bool
    {
        return (bool) $this->validated('is_unlocked');
    }
}
