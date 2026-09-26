<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Http\Requests\Support\Concerns\ValidatesTicketInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The participant closes their own ticket (D-124).
 *
 * @see D-124 · CONSTITUTION art. 5
 */
final class CloseTicketRequest extends FormRequest
{
    use ValidatesTicketInput;

    public function authorize(): bool
    {
        return $this->userMay('close');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
