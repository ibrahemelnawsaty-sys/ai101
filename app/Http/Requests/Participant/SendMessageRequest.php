<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Http\Requests\Concerns\UploadRules;
use App\Models\Thread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Posting into a conversation. Who may post where — announcement channels are
 * staff-only, locked group threads accept nothing from participants — is
 * decided by ThreadPolicy; this class only checks the shape of the message.
 *
 * The body is stored raw and escaped at render time; it is never echoed with
 * unescaped output (CONSTITUTION Art. 24).
 *
 * @see BR-22, BR-33 · PRD §9.13 · CONSTITUTION Art. 5, Art. 24
 */
final class SendMessageRequest extends FormRequest
{
    use UploadRules;

    public function authorize(): bool
    {
        $thread = $this->route('thread');

        return $thread instanceof Thread
            && $this->user() !== null
            && $this->user()->can('post', $thread);
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
            'body' => ['nullable', 'string', 'max:5000'],
            'attachments' => array_merge(['nullable'], $this->fileArrayRules(3)),
            'attachments.*' => $this->fileRules(10240),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasBody = is_string($this->input('body')) && $this->input('body') !== '';
            $hasFiles = is_array($this->file('attachments')) && $this->file('attachments') !== [];

            if (! $hasBody && ! $hasFiles) {
                $validator->errors()->add('body', __('messages.errors.empty'));
            }
        });
    }

    public function thread(): Thread
    {
        /** @var Thread $thread */
        $thread = $this->route('thread');

        return $thread;
    }
}
