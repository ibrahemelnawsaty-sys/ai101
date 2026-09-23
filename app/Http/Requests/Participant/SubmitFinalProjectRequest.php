<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Http\Requests\Concerns\UploadRules;
use App\Models\FinalProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Handing in the final project: three named, required items — a working
 * link, a public GitHub repository and a presentation — plus an optional
 * idea logo (D-110). Reachable only once an administrator has unlocked the
 * project: the policy refuses everything before that, so a direct call to
 * this endpoint fails without disclosing that the project exists (BR-15,
 * BR-16).
 *
 * The slide-count limit the brief states is instructional copy only, not a
 * server check: counting slides needs parsing the uploaded file, and nothing
 * asked for that here — the extension and size rules below are the real gate.
 *
 * @see BR-15, BR-16, BR-19 · PRD §9.14 · CONSTITUTION Art. 5 · D-109, D-110
 */
final class SubmitFinalProjectRequest extends FormRequest
{
    use UploadRules;

    /** Presentation files: no spreadsheets, archives or plain documents. */
    private const PRESENTATION_EXTENSIONS = ['pdf', 'ppt', 'pptx'];

    /** Logo files: images only. */
    private const LOGO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

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
            'live_url' => ['required', 'string', 'url:https', 'max:500'],
            // githubUrlRules() is nullable (an assignment may skip it); this
            // submission may not, so `required` replaces it here rather than
            // being layered on top of it.
            'github_url' => ['required', 'string', 'url:https', 'max:255', 'starts_with:https://github.com/'],
            'presentation_file' => ['required', 'file', 'extensions:'.implode(',', self::PRESENTATION_EXTENSIONS), 'max:'.$this->maxKilobytes()],
            'logo_file' => ['nullable', 'file', 'extensions:'.implode(',', self::LOGO_EXTENSIONS), 'max:'.$this->maxKilobytes()],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'live_url.required' => __('project.errors.live_url_required'),
            'live_url.url' => __('project.errors.live_url_required'),
            'github_url.starts_with' => __('assignments.errors.github_url'),
            'presentation_file.required' => __('project.errors.presentation_required'),
            'presentation_file.extensions' => __('assignments.errors.file_type'),
            'presentation_file.max' => __('assignments.errors.file_size'),
            'logo_file.extensions' => __('assignments.errors.file_type'),
            'logo_file.max' => __('assignments.errors.file_size'),
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
