<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\LandingSetting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Landing-page settings for one cohort: the hero sentence, the FAQ, the
 * countdown, the seat figure shown to visitors and the registration switch.
 *
 * Everything here is content, and content lives in the database so the centre
 * can change it without a developer (BR-31, BR-36).
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · CONSTITUTION Art. 6
 */
final class UpdateLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $setting = $this->route('landing');
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $setting instanceof LandingSetting
            ? $user->can('update', $setting)
            : $user->can('update', new LandingSetting());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_registration_open' => $this->boolean('is_registration_open'),
            'countdown_enabled' => $this->boolean('countdown_enabled'),
        ]);
    }

    /**
     * The editor's field names, which are not the table's.
     *
     * The hero SUBTITLE is the existing `hero_text` column, because that is the
     * value the public page already prints under the headline; renaming it
     * would have broken a live page to satisfy a form. The seats figure on the
     * form is read-only and computed, so only the override is accepted here.
     *
     * The FAQ is not validated here: it has its own three endpoints, and each
     * entry is keyed by a value this server generates (PRD §9.1).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hero_title' => ['required', 'string', 'max:300'],
            'hero_subtitle' => ['nullable', 'string', 'max:2000'],
            'about_body' => ['nullable', 'string', 'max:5000'],
            'seats_override' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'countdown_enabled' => ['required', 'boolean'],
            'is_registration_open' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();

        return [
            'hero_title' => $data['hero_title'],
            'hero_text' => $data['hero_subtitle'] ?? null,
            'about_body' => $data['about_body'] ?? null,
            'seats_remaining_override' => $data['seats_override'] ?? null,
            'countdown_enabled' => (bool) $data['countdown_enabled'],
            'is_registration_open' => (bool) $data['is_registration_open'],
        ];
    }
}
