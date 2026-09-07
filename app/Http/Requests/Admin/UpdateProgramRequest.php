<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ProgramStatus;
use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Editing a programme. Same fields as creation, with the uniqueness check
 * ignoring the row being edited.
 *
 * The English name is NOT rewritten here: the editor has one name field, and
 * silently overwriting `name_en` with the Arabic name on every save would
 * corrupt a value somebody may have set deliberately.
 *
 * @see BR-31, BR-36 · PRD §4.2, §7.2 · CONSTITUTION Art. 6
 */
final class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('program');
        $user = $this->user();

        return $program instanceof Program && $user !== null && $user->can('update', $program);
    }

    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        if (is_string($slug) && trim($slug) !== '') {
            $this->merge(['slug' => Str::slug($slug)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $program = $this->route('program');
        $programId = $program instanceof Program ? $program->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => [
                'required', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('programs', 'slug')->ignore($programId),
            ],
            'summary' => ['required', 'string', 'max:5000'],
            'objectives' => ['nullable', 'string', 'max:6000'],
            'target_audience' => ['nullable', 'string', 'max:6000'],
            'certificates' => ['nullable', 'string', 'max:3000'],
            'hours' => ['required', 'integer', 'min:1', 'max:10000'],
            'status' => ['required', Rule::enum(ProgramStatus::class)],
        ];
    }

    public function program(): Program
    {
        /** @var Program $program */
        $program = $this->route('program');

        return $program;
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        return [
            'name_ar' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['summary'],
            'objectives' => StoreProgramRequest::lines($data['objectives'] ?? null),
            'target_audience' => StoreProgramRequest::lines($data['target_audience'] ?? null),
            'certificates' => StoreProgramRequest::lines($data['certificates'] ?? null),
            'hours' => (int) $data['hours'],
            'status' => $data['status'],
        ];
    }
}
