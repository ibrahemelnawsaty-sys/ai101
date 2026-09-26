<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Http\Requests\Support\Concerns\ValidatesTicketInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Down one level with the answer (D-124). Returning to the coordinator level
 * may name a coordinator of the cohort — the workflow admits the cohort's
 * active coordinators alone, whatever id the form carries.
 *
 * @see D-124 · CONSTITUTION art. 5, art. 22
 */
final class ReturnTicketRequest extends FormRequest
{
    use ValidatesTicketInput;

    public function authorize(): bool
    {
        return $this->userMay('returnDown');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'coordinator_id' => ['nullable', 'string', 'uuid'],
            'return_note' => ['nullable', 'string', 'max:'.self::bodyMax()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'coordinator_id.*' => (string) __('support.errors.coordinator'),
            'return_note.*' => (string) __('support.errors.note', ['max' => self::bodyMax()]),
        ];
    }

    public function coordinatorId(): ?string
    {
        $id = $this->validated('coordinator_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function note(): ?string
    {
        $note = $this->validated('return_note');

        return is_string($note) ? $note : null;
    }
}
