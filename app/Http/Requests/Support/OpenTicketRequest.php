<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Enums\SupportTicketCategory;
use App\Http\Requests\Support\Concerns\ValidatesTicketInput;
use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a support ticket — a participant only (D-124): a subject, a
 * category, a description, and optionally a link and up to three pictures or
 * videos.
 *
 * @see D-124 · CONSTITUTION art. 5, art. 22, art. 24
 */
final class OpenTicketRequest extends FormRequest
{
    use ValidatesTicketInput;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', SupportTicket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:'.self::subjectMax()],
            'category' => ['required', Rule::enum(SupportTicketCategory::class)],
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
            'subject.*' => (string) __('support.errors.subject', ['max' => self::subjectMax()]),
            'category.*' => (string) __('support.errors.category'),
            'body.*' => (string) __('support.errors.body', ['max' => self::bodyMax()]),
            ...$this->attachmentMessages(),
        ];
    }

    public static function subjectMax(): int
    {
        $configured = config('athar.support.subject_max');

        return is_int($configured) && $configured > 0 ? $configured : 150;
    }

    public function category(): SupportTicketCategory
    {
        return SupportTicketCategory::from((string) $this->validated('category'));
    }

    public function subject(): string
    {
        return trim((string) $this->validated('subject'));
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
