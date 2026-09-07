<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\LandingSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * General platform settings reachable from /admin/settings.
 *
 * The display timezone is fixed at Asia/Riyadh by CONSTITUTION Art. 11 and is
 * therefore validated against a one-item allow-list rather than left open: the
 * screen may show it, it may not change it.
 *
 * @see BR-31, BR-36 · PRD §9.18 · CONSTITUTION Art. 11, Art. 6
 */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('update', new LandingSetting());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var list<string> $locales */
        $locales = (array) config('athar.locales.supported', ['ar']);

        return [
            'locale' => ['required', 'string', Rule::in($locales)],
            'timezone' => ['required', 'string', Rule::in([(string) config('athar.display_timezone', 'Asia/Riyadh')])],
        ];
    }
}
