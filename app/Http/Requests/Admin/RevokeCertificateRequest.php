<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Certificate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Revoking an issued certificate. The row is never destroyed — `revoked_at` is
 * stamped and the public verification page then says so (BR-25).
 *
 * @see BR-25, BR-33 · PRD §9.17 · CONSTITUTION Art. 8
 */
final class RevokeCertificateRequest extends FormRequest
{
    public const MIN_REASON_LENGTH = 10;

    public function authorize(): bool
    {
        $certificate = $this->route('certificate');
        $user = $this->user();

        return $certificate instanceof Certificate
            && $user !== null
            && $user->can('revoke', $certificate);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('revoke_reason');

        if (is_string($reason)) {
            $this->merge(['revoke_reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'revoke_reason' => ['required', 'string', 'min:'.self::MIN_REASON_LENGTH, 'max:1000'],
        ];
    }

    public function certificate(): Certificate
    {
        /** @var Certificate $certificate */
        $certificate = $this->route('certificate');

        return $certificate;
    }

    public function reason(): string
    {
        return (string) $this->validated('revoke_reason');
    }
}
