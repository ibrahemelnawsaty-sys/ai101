<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\FinalProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bringing an older version of a guide page back (D-127) — as a new version,
 * so nothing in the history is lost. The general supervisor only.
 *
 * @see D-127 · CONSTITUTION Art. 5
 */
final class RestoreFinalProjectGuideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof FinalProject && $user !== null && $user->can('update', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
