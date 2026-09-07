<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Certificate domain failures.
 *
 * @see BR-26 · PRD §9.17 · CONTRACT §8
 */
final class CertificateException extends DomainException
{
    /** BR-26 — both conditions must hold; neither compensates the other. */
    public static function notEligible(): self
    {
        return new self('certificates.errors.not_eligible', [], 422);
    }

    public static function alreadyIssued(): self
    {
        return new self('certificates.errors.already_issued', [], 409);
    }

    public static function revoked(): self
    {
        return new self('certificates.errors.revoked', [], 410);
    }

    public static function serialGenerationFailed(): self
    {
        return new self('certificates.errors.serial_generation_failed', [], 500);
    }

    public static function overrideReasonRequired(int $minimum): self
    {
        return new self('certificates.errors.override_reason_required', ['min' => $minimum], 422);
    }
}
