<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Models\Notification;
use App\Presenters\Participant\PreferencePresenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Notification preferences, one row per event type, two channels per row.
 *
 * WHY THE RULES WERE REWRITTEN
 * They validated a payload the form never sent. The rules required seven flat
 * top-level keys — `email_enabled`, `in_app_enabled`, `session_reminders`,
 * `grade_updates`, … — while the form has always posted
 * `prefs[<type>][platform]` and `prefs[<type>][email]`. So every press of save
 * came straight back with seven "required" errors bound to field names that do
 * not exist on the page, which means no error rendered next to any control
 * either: the trainee toggled a switch, pressed save, and the page reloaded
 * with their change gone and nothing said (D-65).
 *
 * The rules now describe what the form actually sends, and the type keys are
 * checked against the matrix in `lang/<locale>/notifications.php` so a posted
 * key can never create a preference row for a type that does not exist.
 *
 * An unchecked `x-ui.switch` still arrives: the component renders a hidden
 * input carrying the off value before the checkbox, so both channels are
 * present for every row the form shows.
 *
 * @see BR-33, BR-34 · PRD §9.16 · CONSTITUTION Art. 5 · D-65
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
            'prefs' => ['required', 'array'],
            'prefs.*' => ['array'],
            'prefs.*.platform' => ['sometimes', 'boolean'],
            'prefs.*.email' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $posted = $this->input('prefs');

            if (! is_array($posted)) {
                return;
            }

            $known = self::knownTypes();

            foreach (array_keys($posted) as $type) {
                if (! in_array((string) $type, $known, true)) {
                    $validator->errors()->add('prefs', __('validation.in', ['attribute' => (string) $type]));
                }
            }
        });
    }

    /**
     * Each posted type with both channels as real booleans.
     *
     * A type that must always reach the account is forced on here, not merely
     * rendered as a disabled switch: a disabled control is a browser hint, and
     * the hidden off-value the switch posts beside it would otherwise turn off a
     * certificate or an enrolment decision for anyone who edited the form
     * (Article 5 — hiding a control is not protection).
     *
     * @return array<string, array{platform: bool, email: bool}>
     */
    public function preferences(): array
    {
        /** @var array<string, array<string, mixed>> $posted */
        $posted = (array) ($this->validated()['prefs'] ?? []);
        $result = [];

        foreach ($posted as $type => $channels) {
            $type = (string) $type;

            if (in_array($type, PreferencePresenter::ALWAYS_ON, true)) {
                $result[$type] = ['platform' => true, 'email' => true];

                continue;
            }

            $result[$type] = [
                'platform' => filter_var($channels['platform'] ?? false, FILTER_VALIDATE_BOOL),
                'email' => filter_var($channels['email'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        return $result;
    }

    /**
     * The event types the platform defines — the single list, from the copy.
     *
     * @return list<string>
     */
    private static function knownTypes(): array
    {
        $types = __('notifications.types');

        return is_array($types) ? array_map('strval', array_keys($types)) : [];
    }
}
