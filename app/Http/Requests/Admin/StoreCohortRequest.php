<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CohortStatus;
use App\Models\Cohort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a cohort.
 *
 * `pass_score` and `min_attendance_rate` are the two certificate conditions
 * (BR-26); they are stored per cohort so a decision about one run never leaks
 * into another. The two dates are calendar dates in Asia/Riyadh — the form
 * calls them `starts_at` / `ends_at`, the table calls them `start_date` /
 * `end_date`, and `columns()` below is the only place that knows both.
 *
 * @see BR-26, BR-31 · PRD §4.2, §7.2 · CONSTITUTION Art. 6, Art. 11
 */
final class StoreCohortRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Cohort::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'program_id' => ['required', 'string', 'uuid', Rule::exists('programs', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'ends_at' => ['required', 'date_format:Y-m-d', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'registration_closes_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'pass_score' => ['required', 'integer', 'min:0', 'max:100'],
            'min_attendance_rate' => ['required', 'integer', 'min:0', 'max:100'],
            // PRD §9.2.3 — a cohort that requires approval is the only kind
            // that produces rows on the admin registrations queue.
            'requires_approval' => ['nullable', 'boolean'],
            'status' => ['required', Rule::enum(CohortStatus::class)],
        ];
    }

    /**
     * Validated payload rewritten with `cohorts` column names.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        return [
            'program_id' => $data['program_id'],
            'name' => $data['name'],
            'start_date' => $data['starts_at'],
            'end_date' => $data['ends_at'],
            'capacity' => (int) $data['capacity'],
            'pass_score' => (int) $data['pass_score'],
            'min_attendance_rate' => (int) $data['min_attendance_rate'],
            'requires_approval' => $this->boolean('requires_approval'),
            'status' => $data['status'],
        ];
    }

    /** The closing instant as the visitor typed it, in Riyadh wall time. */
    public function registrationClosesAtRiyadh(): ?string
    {
        $value = $this->validated('registration_closes_at');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
