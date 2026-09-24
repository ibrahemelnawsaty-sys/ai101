<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\LandingContent;
use App\Services\Landing\LandingCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Put one section of the landing page — or the whole page — back to the texts
 * in the lang files, by deleting the published overrides.
 *
 * Only texts are reset. The cohort's switches, its seat override, its hero
 * copy and its questions are the centre's data, not copies of an original, so
 * there is nothing to reset them TO; the editor's confirmation says so.
 *
 * @see BR-31 · PRD §9.18 · D-114
 */
final class ResetLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', LandingContent::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sections = array_column(app(LandingCatalog::class)->sections(), 'key');

        return [
            'section' => ['nullable', 'string', Rule::in($sections)],
        ];
    }

    /** The section to reset, or null for the whole page. */
    public function section(): ?string
    {
        $section = $this->validated('section');

        return is_string($section) && $section !== '' ? $section : null;
    }
}
