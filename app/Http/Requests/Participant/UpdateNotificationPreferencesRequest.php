<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Models\Notification;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Notification preferences. Every channel defaults to on and is switched off
 * explicitly, so an unchecked box is a real "no" rather than a missing key.
 *
 * @see BR-33, BR-34 · PRD §9.16 · CONSTITUTION Art. 5
 */
final class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('updatePreferences', Notification::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email_enabled' => ['required', 'boolean'],
            'in_app_enabled' => ['required', 'boolean'],
            'session_reminders' => ['required', 'boolean'],
            'assignment_reminders' => ['required', 'boolean'],
            'grade_updates' => ['required', 'boolean'],
            'new_resources' => ['required', 'boolean'],
            'new_messages' => ['required', 'boolean'],
        ];
    }
}
