<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\LandingSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The platform-wide notification matrix (PRD §9.16.1).
 *
 * WHY THIS REQUEST EXISTS
 * `SettingController::notifications()` was validated by `UpdateSettingsRequest`,
 * which belongs to the OTHER form on the settings screen and requires `locale`
 * and `timezone`. The notification matrix posts `defaults[<type>][platform]`
 * and `defaults[<type>][email]` and neither of those two fields, so every save
 * failed validation — with the two errors bound to fields that live in a
 * different card — and nothing was ever recorded. One FormRequest for two
 * payloads is a contract that cannot be satisfied (D-65).
 *
 * Authorised exactly as the settings form is: the same people may change both.
 *
 * @see PRD §9.16.1 · CONSTITUTION Art. 5 · D-65
 */
final class UpdateNotificationDefaultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('update', new LandingSetting);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'defaults' => ['required', 'array'],
            'defaults.*' => ['array'],
            'defaults.*.platform' => ['sometimes', 'boolean'],
            'defaults.*.email' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $posted = $this->input('defaults');

            if (! is_array($posted)) {
                return;
            }

            $types = __('notifications.types');
            $known = is_array($types) ? array_map('strval', array_keys($types)) : [];

            // A posted key must name a type the platform defines; the trail
            // must not record a default for an event that does not exist.
            foreach (array_keys($posted) as $type) {
                if (! in_array((string) $type, $known, true)) {
                    $validator->errors()->add('defaults', __('validation.in', ['attribute' => (string) $type]));
                }
            }
        });
    }
}
