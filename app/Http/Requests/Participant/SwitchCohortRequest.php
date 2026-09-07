<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Switching the active cohort in the header (PRD §4.4).
 *
 * One person can be a trainer in one cohort and a participant in another. The
 * switch is a preference, never a permission: the chosen id must already be one
 * the account may reach, and every screen re-checks reach on its own anyway
 * (BR-22, BR-23, BR-28).
 *
 * @see BR-22, BR-23, BR-28 · PRD §4.4 · CONSTITUTION Art. 22
 */
final class SwitchCohortRequest extends FormRequest
{
    /** Session key holding the chosen cohort. */
    public const SESSION_KEY = 'athar.active_cohort_id';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $reachable = $user === null ? [] : $user->accessibleCohortIds();

        return [
            'cohort_id' => ['required', 'string', 'uuid', Rule::in($reachable)],
        ];
    }

    public function cohortId(): string
    {
        return (string) $this->validated('cohort_id');
    }
}
