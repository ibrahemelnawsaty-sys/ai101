<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Broadcast;
use App\Models\Cohort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An administrator's own message to one cohort's trainees (D-87).
 *
 * The body keeps its line breaks — the letter prints paragraphs — and is
 * trimmed at its ends only. The subject is a single line. Neither is ever
 * printed as HTML: the letter escapes both.
 *
 * @see PRD §9.16, §9.18 · BR-28, BR-33 · D-87
 */
final class SendBroadcastRequest extends FormRequest
{
    /**
     * Its own bag, as the reminder form has its own: the page carries both,
     * each with a cohort field, and neither may show the other's errors.
     *
     * @var string
     */
    protected $errorBag = 'broadcast';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Broadcast::class);
    }

    protected function prepareForValidation(): void
    {
        $subject = $this->input('subject');
        $body = $this->input('body');

        $this->merge([
            'subject' => is_string($subject) ? trim((string) preg_replace('/\s+/u', ' ', $subject)) : $subject,
            'body' => is_string($body) ? trim($body) : $body,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cohort_id' => ['required', 'string', Rule::exists('cohorts', 'id')],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:3', 'max:'.max(1, (int) config('athar.broadcasts.body_max', 5000))],
            'in_app' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cohort_id' => (string) __('admin.broadcasts.fields.cohort'),
            'subject' => (string) __('admin.broadcasts.fields.subject'),
            'body' => (string) __('admin.broadcasts.fields.body'),
        ];
    }

    public function cohort(): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = Cohort::query()->whereKey((string) $this->validated('cohort_id'))->firstOrFail();

        return $cohort;
    }

    public function subjectLine(): string
    {
        return (string) $this->validated('subject');
    }

    public function bodyText(): string
    {
        return (string) $this->validated('body');
    }

    public function inApp(): bool
    {
        return $this->boolean('in_app', true);
    }
}
