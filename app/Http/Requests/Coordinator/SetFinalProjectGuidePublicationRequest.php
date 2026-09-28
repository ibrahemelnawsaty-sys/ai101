<?php

declare(strict_types=1);

namespace App\Http\Requests\Coordinator;

use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publishing one language of the guide to the cohort, or taking it down
 * (D-127) — the cohort's primary coordinator, once the general supervisor made
 * that language available, and English never before Arabic
 * (FinalProjectGuidePolicy::publish / unpublish).
 *
 * @see D-127 · CONSTITUTION Art. 5
 */
final class SetFinalProjectGuidePublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $guide = $this->guide();
        $user = $this->user();

        if (! $guide instanceof FinalProjectGuide || $user === null) {
            return false;
        }

        return $user->can($this->publishing() ? 'publish' : 'unpublish', $guide);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['published' => ['required', 'boolean']];
    }

    /** See SetFinalProjectPublicationRequest::publishing(). */
    public function publishing(): bool
    {
        return filter_var($this->input('published'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /** The guide row of the routed project and language, when it exists. */
    public function guide(): ?FinalProjectGuide
    {
        $project = $this->route('project');

        if (! $project instanceof FinalProject) {
            return null;
        }

        /** @var FinalProjectGuide|null $guide */
        $guide = FinalProjectGuide::query()
            ->where('final_project_id', $project->getKey())
            ->where('locale', (string) $this->route('locale'))
            ->first();

        return $guide;
    }
}
