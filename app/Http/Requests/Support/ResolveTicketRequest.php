<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Http\Requests\Support\Concerns\ValidatesTicketInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Resolved» — the coordinator holding the ticket, with an optional summary
 * for the participant (D-124).
 *
 * @see D-124 · CONSTITUTION art. 5
 */
final class ResolveTicketRequest extends FormRequest
{
    use ValidatesTicketInput;

    public function authorize(): bool
    {
        return $this->userMay('resolve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['summary' => ['nullable', 'string', 'max:'.self::bodyMax()]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['summary.*' => (string) __('support.errors.note', ['max' => self::bodyMax()])];
    }

    public function summary(): ?string
    {
        $summary = $this->validated('summary');

        return is_string($summary) ? $summary : null;
    }
}
