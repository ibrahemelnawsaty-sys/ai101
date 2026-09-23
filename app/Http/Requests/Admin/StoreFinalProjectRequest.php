<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cohort;
use App\Models\FinalProject;
use App\Services\Time\Clock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening — or editing — the final project for one cohort (D-110).
 *
 * D-109/D-110 moved every setting of the closing project (the brief, the
 * deadline, the ceiling, the late policy, and publishing it) to the
 * administrator; a trainer's own screen keeps reading the brief and grading,
 * nothing else. One request handles both the first save (nothing exists yet
 * for this cohort) and every edit after it, mirroring
 * Admin\LandingController's firstOrNew — a cohort has at most one project row.
 *
 * @see D-109, D-110 · PRD §9.14 · BR-15, BR-16 · CONSTITUTION Art. 5, Art. 22
 */
final class StoreFinalProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $cohortId = $this->input('cohort_id');
        $project = FinalProject::query()
            ->when(is_string($cohortId), fn ($query) => $query->where('cohort_id', $cohortId))
            ->first() ?? new FinalProject(['cohort_id' => is_string($cohortId) ? $cohortId : null]);

        return $user->can('update', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cohort_id' => ['required', 'string', Rule::exists('cohorts', 'id')],
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:20000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['required', 'date'],
            'max_score' => ['required', 'integer', 'min:1', 'max:1000'],
            'allow_late' => ['sometimes', 'boolean'],
            'is_unlocked' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cohort_id' => (string) __('admin.final_project.fields.cohort'),
            'title' => (string) __('admin.final_project.fields.title'),
            'brief' => (string) __('admin.final_project.fields.brief'),
            'due_at' => (string) __('admin.final_project.fields.due_at'),
            'max_score' => (string) __('admin.final_project.fields.max_score'),
        ];
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = Cohort::query()->whereKey((string) $this->validated('cohort_id'))->firstOrFail();

        return $cohort;
    }

    public function unlocks(): bool
    {
        return $this->boolean('is_unlocked');
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        // One requirement per line, exactly how every other "one per line"
        // admin textarea round-trips through PresentsFormValues::linesFrom().
        $requirements = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', (string) ($data['requirements'] ?? '')) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        return [
            'title' => $data['title'],
            'brief' => $data['brief'],
            'requirements' => $requirements === [] ? null : $requirements,
            'due_at' => Clock::fromRiyadh((string) $data['due_at']),
            'max_score' => $data['max_score'],
            'allow_late' => $this->boolean('allow_late'),
            'is_unlocked' => $this->boolean('is_unlocked'),
        ];
    }
}
