<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CohortStatus;
use App\Models\Cohort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a cohort. The capacity may never drop below the number of seats
 * already taken — shrinking a cohort must not silently evict anybody.
 *
 * @see BR-26, BR-31 · PRD §4.2, §7.2 · CONSTITUTION Art. 6
 */
final class UpdateCohortRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cohort = $this->route('cohort');
        $user = $this->user();

        return $cohort instanceof Cohort && $user !== null && $user->can('update', $cohort);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cohort = $this->route('cohort');
        $seatsTaken = $cohort instanceof Cohort ? (int) $cohort->seats_taken : 0;

        return [
            'name' => ['required', 'string', 'max:120'],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'ends_at' => ['required', 'date_format:Y-m-d', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:'.max($seatsTaken, 1), 'max:10000'],
            'registration_closes_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'pass_score' => ['required', 'integer', 'min:0', 'max:100'],
            'min_attendance_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'requires_approval' => ['nullable', 'boolean'],
            'status' => ['required', Rule::enum(CohortStatus::class)],
        ];
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = $this->route('cohort');

        return $cohort;
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        return [
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

    public function registrationClosesAtRiyadh(): ?string
    {
        $value = $this->validated('registration_closes_at');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
