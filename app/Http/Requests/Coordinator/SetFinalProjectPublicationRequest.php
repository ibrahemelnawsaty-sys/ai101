<?php

declare(strict_types=1);

namespace App\Http\Requests\Coordinator;

use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Publishing the final project to the trainees, or taking it down (D-127) —
 * the cohort's primary coordinator, and only once the general supervisor made
 * it available (FinalProjectPolicy::publish / unpublish).
 *
 * Taking it down after hand-ins arrived asks for an explicit confirmation
 * (`confirmed`): the screen shows how many hand-ins exist, and the server
 * refuses the press without it. The hand-ins themselves are never touched.
 *
 * @see D-127 · BR-15, BR-16, BR-19 · CONSTITUTION Art. 5
 */
final class SetFinalProjectPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        if (! $project instanceof FinalProject || $user === null) {
            return false;
        }

        return $user->can($this->publishing() ? 'publish' : 'unpublish', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'published' => ['required', 'boolean'],
            'confirmed' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->publishing()) {
                return;
            }

            if (! $this->boolean('confirmed') && $this->handInCount() > 0) {
                $validator->errors()->add('confirmed', (string) __('coordinator.final_project.errors.confirm_unpublish'));
            }
        });
    }

    /**
     * Read straight from the input, before validation, because authorize()
     * runs first and must know which ability to ask. Anything but an explicit
     * "publish" asks the narrower question (`unpublish`); the `boolean` rule
     * then refuses a value that is neither.
     */
    public function publishing(): bool
    {
        return filter_var($this->input('published'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    private function handInCount(): int
    {
        /** @var FinalProject $project */
        $project = $this->route('project');

        return ProjectSubmission::query()->where('final_project_id', $project->getKey())->count();
    }
}
