<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Http\Requests\Support\Concerns\ValidatesTicketInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Up one level (D-124), with an optional note for whoever receives it —
 * internal, never shown to the participant.
 *
 * @see D-124 · CONSTITUTION art. 5
 */
final class EscalateTicketRequest extends FormRequest
{
    use ValidatesTicketInput;

    public function authorize(): bool
    {
        return $this->userMay('escalate');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['escalate_note' => ['nullable', 'string', 'max:'.self::bodyMax()]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['escalate_note.*' => (string) __('support.errors.note', ['max' => self::bodyMax()])];
    }

    public function note(): ?string
    {
        $note = $this->validated('escalate_note');

        return is_string($note) ? $note : null;
    }
}
