<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Http\Requests\Trainer\Concerns\ScopedToCohort;
use App\Models\Session;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a session to the schedule.
 *
 * date, start_time and end_time are Riyadh wall-clock values exactly as the
 * table stores them; only App\Services\Time\Clock may turn them into an
 * instant, and nothing here does arithmetic on them beyond after: (BR-07).
 *
 * The cohort comes from the resolved scope, never from the payload (BR-23).
 *
 * @see BR-07, BR-23, BR-24 · PRD §9.8, §9.10 · CONSTITUTION Art. 11, Art. 22
 */
final class StoreSessionRequest extends FormRequest
{
    use ScopedToCohort;

    public function authorize(): bool
    {
        $user = $this->user();
        $cohortId = $this->scopedCohortId();

        if ($user === null || $cohortId === null) {
            return false;
        }

        $draft = new Session();
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
            'week_id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('weeks', 'id')->where('cohort_id', $cohortId),
            ],
            // The editor offers one subject line; it fills both the stored
            // title and the topic, which is what the schedule prints.
            'topic' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::enum(SessionType::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'trainer_id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('enrollments', 'user_id')
                    ->where('cohort_id', $cohortId)
                    ->where('role_in_cohort', 'trainer'),
            ],
            'meeting_url' => ['nullable', 'string', 'url:https', 'max:500'],
            'meeting_passcode' => ['nullable', 'string', 'max:60'],
            // A new session is scheduled; cancelling and completing are their
            // own endpoints, so the editor never posts a status.
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
        ];
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
            'title' => $data['topic'],
            'topic' => $data['topic'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'date' => $data['date'],
            'start_time' => $data['start_time'].':00',
            'end_time' => $data['end_time'].':00',
            'trainer_id' => $data['trainer_id'] ?? null,
            'zoom_url' => $data['meeting_url'] ?? null,
            'zoom_passcode' => $data['meeting_passcode'] ?? null,
            'status' => $data['status'] ?? SessionStatus::Scheduled->value,
        ];
    }
}
