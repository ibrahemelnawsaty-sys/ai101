<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving or rejecting a pending registration (PRD §9.2.3, §9.18).
 *
 * A rejection must say why: the applicant is told, and the trail keeps the
 * sentence. An approval carries no payload at all.
 *
 * @see BR-33 · PRD §4.2, §9.2.3 · CONSTITUTION Art. 8
 */
final class RegistrationDecisionRequest extends FormRequest
{
    public const MIN_REASON_LENGTH = 10;

    public function authorize(): bool
    {
        $enrollment = $this->route('enrollment');
        $user = $this->user();

        if (! $enrollment instanceof Enrollment || $user === null) {
            return false;
        }

        return $this->rejects()
            ? $user->can('reject', $enrollment)
            : $user->can('approve', $enrollment);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reject_reason');

        if (is_string($reason)) {
            $this->merge(['reject_reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->rejects()
            ? ['reject_reason' => ['required', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000']]
            : [];
    }

    public function enrollment(): Enrollment
    {
        /** @var Enrollment $enrollment */
        $enrollment = $this->route('enrollment');

        return $enrollment;
    }

    /** The route name decides the verdict, never a hidden field in the form. */
    public function rejects(): bool
    {
        return str_ends_with((string) $this->route()?->getName(), '.reject');
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reject_reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
