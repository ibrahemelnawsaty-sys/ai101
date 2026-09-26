<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Http\Requests\Support\Concerns\ValidatesTicketInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The participant's answer on their own ticket, at any time before it closes
 * (D-124). An answer to a resolved ticket reopens it with the coordinator.
 *
 * @see D-124 · CONSTITUTION art. 5, art. 24
 */
final class ReplyTicketRequest extends FormRequest
{
    use ValidatesTicketInput;

    public function authorize(): bool
    {
        return $this->userMay('reply');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::bodyMax()],
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
}
