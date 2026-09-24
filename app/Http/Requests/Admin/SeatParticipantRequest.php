<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CohortStatus;
use App\Enums\UserRole;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Seating an existing participant account in a cohort, from the cohorts screen
 * (D-84, moved there by D-117).
 *
 * It used to live on the account's own page. D-117 gave that page to the
 * system administrator and left seating with the general supervisor, who does
 * not browse accounts — so the supervisor names the trainee by e-mail address,
 * exactly as they name a trainer or a coordinator on the same screen.
 *
 * The enrolment IS the permission — every participant screen and policy reads
 * it (BR-23) — so only the supervisor may write one, only for a participant
 * account, and only into a cohort that has not finished.
 *
 * @see BR-22, BR-23, BR-33 · PRD §4.2, §9.18 · D-69, D-84, D-117 · CONSTITUTION Art. 22
 */
final class SeatParticipantRequest extends FormRequest
{
    /** A finished cohort has nothing left to join. */
    public const SEATABLE = [CohortStatus::Upcoming, CohortStatus::Open, CohortStatus::Running];

    public function authorize(): bool
    {
        $cohort = $this->route('cohort');
        $user = $this->user();

        return $cohort instanceof Cohort && $user !== null && $user->can('seatParticipant', $cohort);
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'bail', 'required', 'string', 'email:rfc', 'max:190',
                Rule::exists('users', 'email')
                    ->whereNull('deleted_at')
                    ->where('role', UserRole::Participant->value),
            ],
        ];
    }

    /**
     * The two refusals that depend on the cohort as well as the address: a
     * finished cohort, and an account already in this one — whatever the
     * status of that enrolment, since re-seating a withdrawn trainee is a
     * decision about their record, not a new seat.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $seatable = array_map(static fn (CohortStatus $status): string => $status->value, self::SEATABLE);
            $status = $this->cohort()->getAttribute('status');
            $status = $status instanceof CohortStatus ? $status->value : (string) $status;

            if (! in_array($status, $seatable, true)) {
                $validator->errors()->add('email', (string) __('admin.cohorts.seat_closed'));

                return;
            }

            $already = Enrollment::query()
                ->where('cohort_id', $this->cohort()->getKey())
                ->where('user_id', $this->participant()->getKey())
                ->exists();

            if ($already) {
                $validator->errors()->add('email', (string) __('admin.cohorts.participant_already'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.exists' => (string) __('admin.cohorts.participant_not_found')];
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = $this->route('cohort');

        return $cohort;
    }

    public function participant(): User
    {
        /** @var User $participant */
        $participant = User::query()
            ->where('email', (string) $this->input('email'))
            ->where('role', UserRole::Participant->value)
            ->sole();

        return $participant;
    }
}
