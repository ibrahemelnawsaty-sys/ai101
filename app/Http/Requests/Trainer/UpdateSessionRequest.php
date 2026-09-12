<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Enums\SessionType;
use App\Models\Session;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a scheduled session. Cancelling is a separate endpoint because it
 * demands a reason and notifies the cohort (PRD §9.8.2).
 *
 * @see BR-07, BR-23, BR-24 · PRD §9.8, §9.10 · CONSTITUTION Art. 22
 */
final class UpdateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');
        $user = $this->user();

        return $session instanceof Session
            && $user !== null
            && $user->can('update', $session);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $session = $this->route('session');
        $cohortId = $session instanceof Session ? (string) $session->cohort_id : null;

        return [
            'week_id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('weeks', 'id')->where('cohort_id', $cohortId),
            ],
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
            // How early the link appears, in minutes. Null means "use the
            // platform default" — the trainer is choosing, not being forced to
            // restate a value they are happy with (D-52).
            'join_opens_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'recording_url' => ['nullable', 'string', 'url:https', 'max:500'],
            // Cancelling is its own endpoint because it demands a reason and
            // notifies the cohort, so the editor leaves the status alone.
        ];
    }

    public function trainingSession(): Session
    {
        /** @var Session $session */
        $session = $this->route('session');

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        return [
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
            'join_opens_minutes' => $data['join_opens_minutes'] ?? null,
            'recording_url' => $data['recording_url'] ?? null,
            // The editor does not change status in either direction. It
            // accepted any value, so a crafted PATCH could cancel a session
            // with no reason, no audit and no letter — or silently reverse a
            // cancellation the cohort had just been told of (D-77). Cancelling
            // is the cancel endpoint's alone.
            'status' => $this->trainingSession()->getAttribute('status')?->value,
        ];
    }
}
