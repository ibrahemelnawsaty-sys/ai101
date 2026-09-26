<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\SubmissionFieldType;
use App\Enums\SubmissionFileFormat;
use App\Models\FinalProject;
use App\Models\FinalProjectField;
use App\Services\FinalProject\SubmissionFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding a field to a final project's hand-in form, or changing one (D-121).
 *
 * The administrator names the field, describes it, marks it required or not
 * and picks its type from a closed list; an upload field also gets its
 * formats — from a closed list drawn from the platform's own allow-list,
 * never typed in (D-17 still governs that list) — its per-file size and its
 * file count.
 *
 * Two ceilings are the SERVER's, not a matter of taste, and are refused here
 * rather than discovered by a participant on the deadline night:
 *
 *   · every upload field of one project asks for its files in ONE request,
 *     and PHP drops each file past `max_file_uploads` without a word — so the
 *     file counts of all upload fields together may not pass the platform's
 *     `uploads.max_files` (art. 10);
 *   · a project holds at most FinalProjectField::MAX_PER_PROJECT fields.
 *
 * One request serves both routes: the store route carries the project only,
 * the update route the project and the field it owns (scoped binding).
 *
 * @see BR-31, BR-33, BR-36 · FR-PROJ-10 · PRD §9.14.2, §12.5 · D-17, D-110, D-121 · CONSTITUTION Art. 5, Art. 10
 */
final class SaveFinalProjectFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $project = $this->route('project');
        $field = $this->route('field');

        if ($user === null || ! $project instanceof FinalProject) {
            return false;
        }

        return $field instanceof FinalProjectField
            ? $user->can('update', $field)
            : $user->can('update', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isFile = $this->input('type') === SubmissionFieldType::File->value;

        return [
            'type' => ['required', 'string', Rule::enum(SubmissionFieldType::class)],
            'label' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tips' => ['nullable', 'string', 'max:3000'],
            'is_required' => ['sometimes', 'boolean'],
            'formats' => $isFile ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'formats.*' => ['string', 'distinct', Rule::enum(SubmissionFileFormat::class)],
            'max_megabytes' => $isFile
                ? ['required', 'integer', 'min:1', 'max:'.self::platformMaxMegabytes()]
                : ['nullable'],
            'max_files' => $isFile
                ? ['required', 'integer', 'min:1', 'max:'.SubmissionFields::maxFilesPerHandIn()]
                : ['nullable'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $project = $this->route('project');

                if (! $project instanceof FinalProject || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $field = $this->route('field');
                $editing = $field instanceof FinalProjectField ? (string) $field->getKey() : null;
                $fields = $project->fields()->get();

                if ($editing === null && $fields->count() >= FinalProjectField::MAX_PER_PROJECT) {
                    $validator->errors()->add('label', __('admin.final_project.submission_fields.errors.too_many_fields', [
                        'max' => FinalProjectField::MAX_PER_PROJECT,
                    ]));

                    return;
                }

                if ($this->input('type') !== SubmissionFieldType::File->value) {
                    return;
                }

                $asked = SubmissionFields::filesAskedFor($fields, $editing) + (int) $this->input('max_files');
                $max = SubmissionFields::maxFilesPerHandIn();

                if ($asked > $max) {
                    $validator->errors()->add('max_files', __('admin.final_project.submission_fields.errors.too_many_files', [
                        'asked' => $asked,
                        'max' => $max,
                    ]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'formats.required' => (string) __('admin.final_project.submission_fields.errors.formats_required'),
            'formats.min' => (string) __('admin.final_project.submission_fields.errors.formats_required'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => (string) __('admin.final_project.submission_fields.form.type'),
            'label' => (string) __('admin.final_project.submission_fields.form.label'),
            'description' => (string) __('admin.final_project.submission_fields.form.description'),
            'tips' => (string) __('admin.final_project.submission_fields.form.tips'),
            'formats' => (string) __('admin.final_project.submission_fields.form.formats'),
            'formats.*' => (string) __('admin.final_project.submission_fields.form.formats'),
            'max_megabytes' => (string) __('admin.final_project.submission_fields.form.max_megabytes'),
            'max_files' => (string) __('admin.final_project.submission_fields.form.max_files'),
        ];
    }

    /**
     * The row to write. Settings that belong to an upload field are cleared
     * for every other type, so a field turned from "file" into "link" does not
     * keep formats nobody can see any more.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $type = SubmissionFieldType::from((string) $data['type']);

        $tips = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', (string) ($data['tips'] ?? '')) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        $description = trim((string) ($data['description'] ?? ''));

        return [
            'type' => $type,
            'label' => trim((string) $data['label']),
            'description' => $description === '' ? null : $description,
            'tips' => $tips === [] ? null : $tips,
            'is_required' => $this->boolean('is_required'),
            'accepted_formats' => $type->isFile() ? $this->formats() : null,
            'max_kilobytes' => $type->isFile() ? (int) $data['max_megabytes'] * 1024 : null,
            'max_files' => $type->isFile() ? (int) $data['max_files'] : null,
        ];
    }

    /**
     * The chosen formats in the catalogue's own order, so two fields with the
     * same choice store — and show — the same list.
     *
     * @return list<string>
     */
    private function formats(): array
    {
        $chosen = (array) $this->validated('formats', []);

        return array_values(array_filter(
            SubmissionFileFormat::values(),
            static fn (string $format): bool => in_array($format, $chosen, true),
        ));
    }

    private static function platformMaxMegabytes(): int
    {
        return max(1, intdiv(FinalProjectField::platformMaxKilobytes(), 1024));
    }
}
