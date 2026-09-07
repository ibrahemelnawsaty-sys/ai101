<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a message already sent. Only its author, and only within 15 minutes
 * of sending it — MessagePolicy owns that window (PRD §9.13.2).
 *
 * @see BR-22, BR-33 · PRD §9.13.2 · CONSTITUTION Art. 5
 */
final class UpdateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');

        return $message instanceof Message
            && $this->user() !== null
            && $this->user()->can('update', $message);
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => trim($body)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => __('messages.errors.empty'),
        ];
    }

    public function message(): Message
    {
        /** @var Message $message */
        $message = $this->route('message');

        return $message;
    }
}
