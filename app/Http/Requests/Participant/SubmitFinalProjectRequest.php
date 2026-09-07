<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Http\Requests\Concerns\UploadRules;
use App\Models\FinalProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Handing in the final project. Reachable only once a trainer has unlocked the
 * project: the policy refuses everything before that, so a direct call to this
 * endpoint fails without disclosing that the project exists (BR-15, BR-16).
 *
 * @see BR-15, BR-16, BR-19 · PRD §9.14 · CONSTITUTION Art. 5
 */
final class SubmitFinalProjectRequest extends FormRequest
{
    use UploadRules;

    public function authorize(): bool
    {
        $project = $this->finalProject();
        $user = $this->user();

        return $project instanceof FinalProject
            && $user !== null
            && $user->can('submit', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'files' => array_merge(['nullable'], $this->fileArrayRules()),
            'files.*' => $this->fileRules(),
            'github_url' => $this->githubUrlRules(),
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasFiles = is_array($this->file('files')) && $this->file('files') !== [];
            $hasLink = is_string($this->input('github_url')) && trim((string) $this->input('github_url')) !== '';

            if (! $hasFiles && ! $hasLink) {
                $validator->errors()->add('files', __('assignments.errors.nothing_submitted'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'github_url.starts_with' => __('assignments.errors.github_url'),
            'files.*.extensions' => __('assignments.errors.file_type'),
            'files.*.max' => __('assignments.errors.file_size'),
        ];
    }

    /**
     * The endpoint carries no id — `finalProject.submit` is a bare POST — so
     * the project is resolved from the participant's own cohorts. That is the
     * scope check itself: there is no id to tamper with (BR-22).
     */
    public function finalProject(): ?FinalProject
    {
        $user = $this->user();

        if ($user === null) {
            return null;
        }

        /** @var FinalProject|null $project */
        $project = FinalProject::query()
            ->whereIn('cohort_id', $user->accessibleCohortIds())
            ->orderByDesc('updated_at')
            ->first();

        return $project;
    }
}
