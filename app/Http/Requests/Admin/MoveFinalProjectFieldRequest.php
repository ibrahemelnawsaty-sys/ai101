<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\FinalProjectField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving one field of the hand-in form a place up or down (D-121).
 *
 * The order is what the participant's form shows and what every hand-in keeps
 * its answers in, so it is a setting like any other: the general supervisor's
 * alone, and never from a preview (BR-33).
 *
 * @see BR-31, BR-33 · PRD §9.14.2 · D-121 · CONSTITUTION Art. 5
 */
final class MoveFinalProjectFieldRequest extends FormRequest
{
    public const UP = 'up';

    public const DOWN = 'down';

    public function authorize(): bool
    {
        $user = $this->user();
        $field = $this->route('field');

        return $user !== null && $field instanceof FinalProjectField && $user->can('update', $field);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', Rule::in([self::UP, self::DOWN])],
        ];
    }

    public function movesUp(): bool
    {
        return $this->validated('direction') === self::UP;
    }
}
