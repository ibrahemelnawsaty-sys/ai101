<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CohortStatus;
use App\Models\Cohort;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Seating an existing participant account in a cohort (D-84).
 *
 * The enrolment IS the permission — every participant screen and policy reads
 * it (BR-23) — so only an administrator may write one, only for a participant
 * account, and only into a cohort that has not finished.
 *
 * @see BR-22, BR-23, BR-33 · PRD §4.2, §9.18 · D-69, D-84 · CONSTITUTION Art. 22
 */
final class EnrollUserRequest extends FormRequest
{
    /** A finished cohort has nothing left to join. */
    public const SEATABLE = [CohortStatus::Upcoming, CohortStatus::Open, CohortStatus::Running];

    public function authorize(): bool
    {
        $subject = $this->route('user');
        $user = $this->user();

        return $subject instanceof User && $user !== null && $user->can('enroll', $subject);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cohort_id' => [
                'bail', 'required', 'string', 'uuid',
                Rule::exists('cohorts', 'id')->whereIn(
                    'status',
                    array_map(static fn (CohortStatus $s): string => $s->value, self::SEATABLE),
                ),
                // Any enrolment, whatever its status: re-seating a withdrawn
                // trainee is a decision about their record, not a new seat.
                Rule::unique('enrollments', 'cohort_id')->where('user_id', $this->subject()->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['cohort_id.unique' => (string) __('admin.users.enroll_already')];
    }

    public function subject(): User
    {
        /** @var User $subject */
        $subject = $this->route('user');

        return $subject;
    }

    public function cohort(): Cohort
    {
        return Cohort::query()->findOrFail((string) $this->validated('cohort_id'));
    }
}
