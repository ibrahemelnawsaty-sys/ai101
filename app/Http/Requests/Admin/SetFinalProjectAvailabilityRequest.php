<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Making the final project available for its primary coordinator to publish,
 * or withdrawing it (D-127) — the general supervisor, as its own press rather
 * than a box inside the settings form, so an unrelated save from a stale tab
 * can never lock a live project.
 *
 * Withdrawing a PUBLISHED project locks it for the trainees at once. When
 * hand-ins have arrived that needs an explicit confirmation (`confirmed`) —
 * the same rule the coordinator's own unpublish follows. Hand-ins are kept.
 *
 * @see D-127 · BR-15, BR-19 · CONSTITUTION Art. 5, Art. 8
 */
final class SetFinalProjectAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof FinalProject && $user !== null && $user->can('update', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'available' => ['required', 'boolean'],
            'confirmed' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->available()) {
                return;
            }

            /** @var FinalProject $project */
            $project = $this->route('project');

            if ((bool) $project->is_unlocked
                && ! $this->boolean('confirmed')
                && ProjectSubmission::query()->where('final_project_id', $project->getKey())->exists()) {
                $validator->errors()->add('confirmed', (string) __('admin.final_project.errors.confirm_withdraw'));
            }
        });
    }

    public function available(): bool
    {
        return $this->boolean('available');
    }
}
