<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting a conversation (D-118): whom to, and the first message.
 *
 * `recipient` is an account id, or `system_admin` for the system
 * administrators' shared inbox. Whether this account may start a conversation
 * with that recipient is ThreadPolicy's — which asks ConversationRules, the
 * same query that fills the picker. A recipient the picker would not have
 * offered (another cohort's trainer, a system administrator named directly,
 * an unknown id) is refused 403 and the refusal written to the trail (art. 22).
 *
 * A missing recipient is left to the rules below, so an empty form is told
 * what is missing rather than refused.
 *
 * @see BR-22, BR-33 · PRD §9.13 · CONSTITUTION Art. 5, Art. 22 · D-118
 */
final class StartConversationRequest extends FormRequest
{
    public const INBOX = 'system_admin';

    private bool $resolved = false;

    private ?User $recipient = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $recipient = $this->input('recipient');

        if ($user === null) {
            return false;
        }

        if (! is_string($recipient) || $recipient === '') {
            return true;
        }

        if ($recipient === self::INBOX) {
            return $user->can('startInbox', Thread::class);
        }

        $to = $this->recipientUser();

        return $to instanceof User && $user->can('start', [Thread::class, $to]);
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
            'recipient' => ['required', 'string', 'max:36'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient.required' => (string) __('messages.start.recipient_required'),
            'body.required' => (string) __('messages.start.body_required'),
        ];
    }

    public function toInbox(): bool
    {
        return $this->input('recipient') === self::INBOX;
    }

    /**
     * The account named as recipient, looked up ONCE — authorize() and the
     * controller read the same instance, so an account removed between the
     * two cannot turn into something else (D-118 review).
     */
    public function recipientUser(): ?User
    {
        if ($this->resolved) {
            return $this->recipient;
        }

        $this->resolved = true;
        $id = $this->input('recipient');

        if (! is_string($id) || $id === '' || $id === self::INBOX) {
            return $this->recipient = null;
        }

        /** @var User|null $user */
        $user = User::query()->find($id);

        return $this->recipient = $user;
    }
}
