<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Enums\ResourceType;
use App\Http\Requests\Concerns\UploadRules;
use App\Http\Requests\Trainer\Concerns\ScopedToCohort;
use App\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding a resource to the training kit: an uploaded file, or a link.
 *
 * A `file` resource must carry a file and a `link` or `video` resource must
 * carry a URL — the pair is checked here rather than left to a nullable column
 * that would accept an empty entry. The real type check happens after
 * validation, from the file's own bytes, not from its extension (PRD §12.5).
 *
 * @see BR-23 · PRD §9.12, §12.5 · CONSTITUTION Art. 24
 */
final class StoreResourceRequest extends FormRequest
{
    use ScopedToCohort;
    use UploadRules;

    public function authorize(): bool
    {
        $user = $this->user();
        $cohortId = $this->scopedCohortId();

        if ($user === null || $cohortId === null) {
            return false;
        }

        $draft = new Resource();
        $draft->setAttribute('cohort_id', $cohortId);

        return $user->can('create', $draft);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cohortId = $this->scopedCohortId();

        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'resource_type' => ['required', Rule::enum(ResourceType::class)],
            'week_id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('weeks', 'id')->where('cohort_id', $cohortId),
            ],
            'session_id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('sessions', 'id')->where('cohort_id', $cohortId),
            ],
            'url' => ['nullable', 'string', 'url:https', 'max:500'],
            'file' => array_merge(['nullable'], $this->fileRules()),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('resource_type');
            $hasFile = $this->hasFile('file');
            $hasUrl = is_string($this->input('url')) && trim((string) $this->input('url')) !== '';

            if ($type === ResourceType::File->value && ! $hasFile) {
                $validator->errors()->add('file', __('validation.required', [
                    'attribute' => __('validation.attributes.file'),
                ]));
            }

            if ($type !== ResourceType::File->value && ! $hasUrl) {
                $validator->errors()->add('url', __('validation.required', [
                    'attribute' => __('validation.attributes.url'),
                ]));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        return [
            'cohort_id' => $this->scopedCohortId(),
            'week_id' => $data['week_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['resource_type'],
            'external_url' => $data['url'] ?? null,
        ];
    }
}
