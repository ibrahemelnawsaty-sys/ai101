<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\LandingSetting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One question and answer on the landing page (PRD §9.1, §9.18).
 *
 * The text is stored and later rendered escaped: the FAQ is data, never markup,
 * so a compromised field cannot inject anything into the public page
 * (CONSTITUTION Art. 24).
 *
 * @see BR-31, BR-36 · PRD §9.1 · CONSTITUTION Art. 24
 */
final class FaqEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('update', new LandingSetting());
    }

    protected function prepareForValidation(): void
    {
        foreach (['question', 'answer'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function entry(): array
    {
        return [
            'question' => (string) $this->validated('question'),
            'answer' => (string) $this->validated('answer'),
        ];
    }
}
