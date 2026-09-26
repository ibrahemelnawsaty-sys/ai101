<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Http\Requests\Support\Concerns\ValidatesTicketInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A line from the support team: to the participant, or internal (D-124). Only
 * whoever holds the ticket may write to the participant — the workflow keeps
 * anyone else's line internal whatever the form says.
 *
 * @see D-124 · CONSTITUTION art. 5, art. 24
 */
final class NoteTicketRequest extends FormRequest
{
    use ValidatesTicketInput;

    public function authorize(): bool
    {
        return $this->userMay('note');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::bodyMax()],
            'internal' => ['nullable', 'boolean'],
            ...$this->attachmentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.*' => (string) __('support.errors.body', ['max' => self::bodyMax()]),
            ...$this->attachmentMessages(),
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }

    public function internal(): bool
    {
        return $this->boolean('internal');
    }
}
