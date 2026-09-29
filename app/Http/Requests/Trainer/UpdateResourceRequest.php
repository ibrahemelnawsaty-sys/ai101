<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a training-kit item's DATA (FR-RES-10): its title, description, week,
 * session and — for a link or a video — its address.
 *
 * What it does not read is as deliberate as what it does. The type, the stored
 * file, its size, the download count, who uploaded it and which cohort it
 * belongs to are not in `rules()`, so they are not in `validated()` and no form
 * can move them (art. 22); the file itself is replaced by adding new material,
 * never through this form.
 *
 * The week and the session must be this resource's OWN cohort's — the cohort is
 * read from the resource the route names, not from the request (BR-23).
 *
 * @see BR-22, BR-23 · FR-RES-08, FR-RES-10 · PRD §9.12 · CONSTITUTION art. 5, art. 22 · D-136
 */
final class UpdateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = $this->route('resource');
        $user = $this->user();

        return $resource instanceof Resource
            && $user !== null
            && $user->can('update', $resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cohortId = (string) $this->resource()->getAttribute('cohort_id');

        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'week_id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('weeks', 'id')->where('cohort_id', $cohortId),
            ],
            'session_id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('sessions', 'id')->where('cohort_id', $cohortId),
            ],
        ];

        // The address is data for a link or a video only; a file resource has
        // none, and a `url` posted for one is not read.
        if ($this->addressIsEditable()) {
            $rules['url'] = ['required', 'string', 'url:https', 'max:500'];
        }

        return $rules;
    }

    /**
     * The columns to write — only what `rules()` validated, under the column's
     * own name.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        $columns = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'week_id' => $data['week_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
        ];

        if ($this->addressIsEditable()) {
            $columns['external_url'] = $data['url'];
        }

        return $columns;
    }

    public function resource(): Resource
    {
        /** @var resource $resource */
        $resource = $this->route('resource');

        return $resource;
    }

    private function addressIsEditable(): bool
    {
        $type = $this->resource()->getAttribute('type');

        return $type === ResourceType::Link || $type === ResourceType::Video;
    }
}
