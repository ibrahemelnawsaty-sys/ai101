<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Enums\AssignmentStatus;
use App\Http\Requests\Concerns\UploadRules;
use App\Models\Assignment;
use App\Models\Week;
use App\Services\Time\Clock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating an assignment. The trainer sets the title, the weight, the deadline,
 * whether it is mandatory and whether late hand-ins are accepted (BR-17).
 *
 * The assignment is bound to the cohort resolved by the `cohort.scope`
 * middleware — never to a cohort id taken from the request body, which is how
 * a trainer would otherwise write into someone else's cohort (BR-23).
 *
 * @see BR-12, BR-17, BR-18, BR-23 · PRD §9.11.3 · CONSTITUTION Art. 5, Art. 22
 */
final class StoreAssignmentRequest extends FormRequest
{
    use UploadRules;

    public function authorize(): bool
    {
        $cohortId = $this->attributes->get('athar.cohort_id');
        $user = $this->user();

        if ($user === null || ! is_string($cohortId)) {
            return false;
        }

        $probe = new Assignment;
        $probe->cohort_id = $cohortId;

        return $user->can('create', $probe);
    }

    /**
     * Unchecked boxes are simply absent from a POST, so `required|boolean`
     * would reject every form where the trainer turned one off. Normalising
     * them here keeps the rule strict without making absence an error.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_mandatory' => $this->boolean('is_mandatory'),
            'allow_late' => $this->boolean('allow_late'),
            'show_github_field' => $this->boolean('show_github_field'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cohortId = $this->attributes->get('athar.cohort_id');

        return [
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'max:20000'],
            'week_id' => [
                'nullable',
                Rule::exists(Week::class, 'id')->where('cohort_id', $cohortId),
            ],
            'max_score' => ['required', 'numeric', 'min:0', 'max:50'],
            'due_at' => ['required', 'date'],
            'is_mandatory' => ['required', 'boolean'],
            'allow_late' => ['required', 'boolean'],
            'show_github_field' => ['required', 'boolean'],
            'max_files' => ['nullable', 'integer', 'min:1', 'max:'.$this->maxFiles()],
            'max_file_mb' => ['nullable', 'integer', 'min:1', 'max:'.(int) ceil($this->maxKilobytes() / 1024)],
            'status' => ['required', Rule::enum(AssignmentStatus::class)],
            'attachments' => array_merge(['nullable'], $this->fileArrayRules()),
            'attachments.*' => $this->fileRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_score.max' => __('grades.errors.assignment_max_score'),
            'max_score.min' => __('grades.errors.assignment_max_score'),
        ];
    }

    public function cohortId(): string
    {
        return (string) $this->attributes->get('athar.cohort_id');
    }

    /**
     * Validated payload rewritten with `assignments` column names. The form
     * speaks of a GitHub field and a size in kilobytes; the table stores
     * `allow_github_link` and a size in megabytes. This is the only place that
     * knows both, so no controller has to.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        $megabytes = $data['max_file_mb'] ?? null;

        $columns = [
            'week_id' => $data['week_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'],
            'is_mandatory' => (bool) $data['is_mandatory'],
            'max_score' => $data['max_score'],
            'due_at' => Clock::fromRiyadh((string) $data['due_at']),
            'allow_late' => (bool) $data['allow_late'],
            'allow_github_link' => (bool) $data['show_github_field'],
            'status' => $data['status'],
        ];

        // PRD §9.11.2 gives the upload ceilings a platform default; both columns
        // are NOT NULL. An omitted ceiling therefore means «keep the default»,
        // so the key is left out rather than written as null.
        if (is_numeric($data['max_files'] ?? null)) {
            $columns['max_files'] = (int) $data['max_files'];
        }

        if (is_numeric($megabytes)) {
            $columns['max_file_size_mb'] = (int) $megabytes;
        }

        return $columns;
    }
}
