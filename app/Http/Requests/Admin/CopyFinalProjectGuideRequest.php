<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\FinalProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Copying another cohort's guide pages into this cohort's project (D-127). Only
 * the pages travel; this cohort's availability and publication stay its own.
 * The general supervisor only.
 *
 * @see D-127 · CONSTITUTION Art. 5
 */
final class CopyFinalProjectGuideRequest extends FormRequest
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
        return ['source_cohort_id' => ['required', 'string', Rule::exists('cohorts', 'id')]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_cohort_id.required' => (string) __('admin.final_project.guide.errors.copy_source'),
            'source_cohort_id.exists' => (string) __('admin.final_project.guide.errors.copy_source'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->sourceProject() === null) {
                $validator->errors()->add('source_cohort_id', (string) __('admin.final_project.guide.errors.copy_source'));
            }
        });
    }

    /** The other cohort's project — never this project itself. */
    public function sourceProject(): ?FinalProject
    {
        /** @var FinalProject $target */
        $target = $this->route('project');

        /** @var FinalProject|null $source */
        $source = FinalProject::query()
            ->where('cohort_id', (string) $this->input('source_cohort_id'))
            ->whereKeyNot($target->getKey())
            ->first();

        return $source;
    }
}
