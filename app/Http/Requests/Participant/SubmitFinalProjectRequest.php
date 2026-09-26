<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Enums\SubmissionFieldType;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Models\FinalProject;
use App\Models\FinalProjectField;
use App\Models\User;
use App\Presenters\Support\HandInRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Handing in the final project: whatever fields the general supervisor set on
 * it, each checked by its own rules (D-121).
 *
 * The rules are built from the project's fields on every request — nothing
 * here names a field. `answers.{field id}` carries a string for a link or a
 * text, and a list of files for an upload field:
 *
 *   · url       → https only, 500 characters (D-110's own rule)
 *   · github    → https only and starts with https://github.com/, 255
 *   · text      → 500 characters · textarea → 5000
 *   · file      → how many files the field allows, each one with an extension
 *                 of the field's formats and no larger than the field's limit.
 *                 The bytes are checked again at storage against the same
 *                 formats (PrivateFileService::store(), art. 24).
 *
 * Every refusal names the field by the label the participant sees and says
 * what to do (art. 15), and lands on that field's own key, so the form shows
 * it under the field it is about.
 *
 * Reachable only once an administrator has unlocked the project: the policy
 * refuses everything before that, so a direct call to this endpoint fails
 * without disclosing that the project exists (BR-15, BR-16). The project is
 * the one of the participant's ACTIVE cohort — the same one the page showed —
 * so the fields checked here are the fields the participant was shown.
 *
 * The slide-count limit a field's description may state is instructional copy
 * only, not a server check: counting slides needs parsing the file (D-110).
 *
 * @see BR-15, BR-16, BR-19, BR-22 · FR-PROJ-10, FR-PROJ-11 · PRD §9.14, §12.5 · CONSTITUTION Art. 5, Art. 15, Art. 24 · D-110, D-121
 */
final class SubmitFinalProjectRequest extends FormRequest
{
    use ResolvesActiveCohort;

    private ?FinalProject $project = null;

    private bool $projectResolved = false;

    /** @var Collection<int, FinalProjectField>|null */
    private ?Collection $fields = null;

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
        $rules = ['answers' => ['nullable', 'array']];

        foreach ($this->fields() as $field) {
            $rules[self::key($field)] = $field->isFile()
                ? $this->fileRules($field)
                : $this->textRules($field);
        }

        return $rules;
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->fields()->isEmpty()) {
                    $validator->errors()->add('answers', __('project.errors.no_fields'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->fields() as $field) {
            $key = self::key($field);
            $label = ['field' => (string) $field->getAttribute('label')];
            $type = $field->fieldType();

            $messages[$key.'.required'] = (string) __('project.errors.field_required', $label);
            $messages[$key.'.string'] = (string) __('project.errors.field_invalid', $label);
            $messages[$key.'.array'] = (string) __('project.errors.field_invalid', $label);
            $messages[$key.'.max'] = (string) __('project.errors.field_too_long', $label + ['max' => $type->maxLength()]);
            $messages[$key.'.url'] = (string) __($type === SubmissionFieldType::Github ? 'project.errors.field_github' : 'project.errors.field_url', $label);
            $messages[$key.'.starts_with'] = (string) __('project.errors.field_github', $label);
        }

        return $messages;
    }

    /**
     * The project of the participant's active cohort — resolved once. The
     * endpoint carries no id, so there is no id to tamper with (BR-22).
     */
    public function finalProject(): ?FinalProject
    {
        if ($this->projectResolved) {
            return $this->project;
        }

        $this->projectResolved = true;
        $user = $this->user();

        if (! $user instanceof User) {
            return null;
        }

        $project = $this->activeCohort($user)?->finalProject()->first();

        return $this->project = $project instanceof FinalProject ? $project : null;
    }

    /**
     * The project's fields, in the order the form showed them.
     *
     * @return Collection<int, FinalProjectField>
     */
    public function fields(): Collection
    {
        return $this->fields ??= $this->finalProject()?->fields()->get() ?? new Collection;
    }

    /** What was typed into a link or text field, trimmed; null when empty. */
    public function valueFor(FinalProjectField $field): ?string
    {
        $value = $this->validated(self::key($field));

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The files sent through an upload field, empty slots dropped.
     *
     * @return list<UploadedFile>
     */
    public function filesFor(FinalProjectField $field): array
    {
        return self::uploads($this->file(self::key($field)));
    }

    /** Where a field's value sits in the request and in the error bag. */
    public static function key(FinalProjectField $field): string
    {
        return 'answers.'.$field->getKey();
    }

    /**
     * @return list<string>
     */
    private function textRules(FinalProjectField $field): array
    {
        $type = $field->fieldType();
        $rules = [$field->isRequired() ? 'required' : 'nullable', 'string', 'max:'.$type->maxLength()];

        if ($type->isLink()) {
            $rules[] = 'url:https';
        }

        if ($type === SubmissionFieldType::Github) {
            $rules[] = 'starts_with:https://github.com/';
        }

        return $rules;
    }

    /**
     * `bail` first: a missing required upload says so once, not twice.
     *
     * @return list<mixed>
     */
    private function fileRules(FinalProjectField $field): array
    {
        return [
            'bail',
            $field->isRequired() ? 'required' : 'nullable',
            'array',
            function (string $attribute, mixed $value, \Closure $fail) use ($field): void {
                $files = self::uploads($value);
                $label = (string) $field->getAttribute('label');

                if ($files === []) {
                    if ($field->isRequired()) {
                        $fail((string) __('project.errors.field_required', ['field' => $label]));
                    }

                    return;
                }

                $max = $field->maxFiles();

                if (count($files) > $max) {
                    $fail((string) trans_choice('project.errors.field_file_count', $max, ['field' => $label, 'count' => $max]));

                    return;
                }

                $extensions = $field->acceptedExtensions();
                $size = ['field' => $label, 'size' => HandInRules::size($field->maxKilobytes())];

                foreach ($files as $file) {
                    if (! $file->isValid()) {
                        $fail(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                            ? (string) __('project.errors.field_file_size', $size)
                            : (string) __('project.errors.field_invalid', ['field' => $label]));

                        return;
                    }

                    if (! in_array(strtolower($file->getClientOriginalExtension()), $extensions, true)) {
                        $fail((string) __('project.errors.field_file_type', [
                            'field' => $label,
                            'formats' => HandInRules::formats($field),
                        ]));

                        return;
                    }

                    if ((int) $file->getSize() > $field->maxKilobytes() * 1024) {
                        $fail((string) __('project.errors.field_file_size', $size));

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    private static function uploads(mixed $value): array
    {
        $files = [];

        foreach (is_array($value) ? $value : [$value] as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
