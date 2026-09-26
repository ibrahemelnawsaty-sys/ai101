<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Http\Requests\Support\Concerns\ValidatesTicketInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The primary coordinator hands a ticket they hold to another coordinator of
 * the cohort (D-124). The workflow admits the cohort's active coordinators
 * alone, whatever id the form carries.
 *
 * @see D-124 · CONSTITUTION art. 5, art. 22
 */
final class AssignTicketRequest extends FormRequest
{
    use ValidatesTicketInput;

    public function authorize(): bool
    {
        return $this->userMay('assign');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'string', 'uuid'],
            'assign_note' => ['nullable', 'string', 'max:'.self::bodyMax()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.*' => (string) __('support.errors.coordinator'),
            'assign_note.*' => (string) __('support.errors.note', ['max' => self::bodyMax()]),
        ];
    }

    public function coordinatorId(): string
    {
        return (string) $this->validated('assignee_id');
    }

    public function note(): ?string
    {
        $note = $this->validated('assign_note');

        return is_string($note) ? $note : null;
    }
}
