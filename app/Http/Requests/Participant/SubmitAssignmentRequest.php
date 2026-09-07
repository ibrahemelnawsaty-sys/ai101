<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Http\Requests\Concerns\UploadRules;
use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Handing in an assignment.
 *
 * A submission needs at least one file or a GitHub link — an empty hand-in is
 * refused on the server, not merely by a disabled button. The per-assignment
 * file count and size ceilings set by the trainer are applied here, capped by
 * the platform-wide limits (BR-17).
 *
 * Late hand-ins are accepted or refused according to the assignment's own
 * `allow_late` flag (BR-18); the deadline comparison uses the server clock and
 * is made by the submission service, which owns that decision.
 *
 * @see BR-17, BR-18, BR-19, BR-22 · PRD §9.11.2 · CONSTITUTION Art. 5
 */
final class SubmitAssignmentRequest extends FormRequest
{
    use UploadRules;

    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof Assignment
            && $this->user() !== null
            && $this->user()->can('submit', $assignment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assignment = $this->assignment();

        $maxFiles = $assignment->getAttribute('max_files');
        // The column is expressed in megabytes; validation counts kilobytes.
        $maxMegabytes = $assignment->getAttribute('max_file_size_mb');
        $maxKilobytes = is_numeric($maxMegabytes) && (int) $maxMegabytes > 0
            ? (int) $maxMegabytes * 1024
            : null;

        return [
            'files' => array_merge(['nullable'], $this->fileArrayRules(is_numeric($maxFiles) ? (int) $maxFiles : null)),
            'files.*' => $this->fileRules($maxKilobytes),
            'github_url' => $this->githubUrlRules(),
            'note' => ['nullable', 'string', 'max:2000'],
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
            'github_url.url' => __('assignments.errors.github_url'),
            'files.max' => __('assignments.errors.too_many_files'),
            'files.*.extensions' => __('assignments.errors.file_type'),
            'files.*.max' => __('assignments.errors.file_size'),
        ];
    }

    public function assignment(): Assignment
    {
        /** @var Assignment $assignment */
        $assignment = $this->route('assignment');

        return $assignment;
    }
}
