<?php

declare(strict_types=1);

namespace App\Http\Requests\Participant;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reporting an abusive message to the admins (PRD §9.13.2).
 *
 * @see BR-22, BR-27, BR-33 · PRD §9.13.2 · CONSTITUTION Art. 8
 */
final class ReportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');

        return $message instanceof Message
            && $this->user() !== null
            && $this->user()->can('report', $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => __('messages.errors.report_reason_short'),
            'reason.required' => __('messages.errors.report_reason_short'),
        ];
    }

    public function message(): Message
    {
        /** @var Message $message */
        $message = $this->route('message');

        return $message;
    }
}
