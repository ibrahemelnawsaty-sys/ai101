<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Certificate;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * One issued certificate on the register.
 *
 * Revocation is a timestamp, never a deleted row, so a revoked certificate is
 * still listed and still says so — which is exactly what the public
 * verification page needs in order to answer "revoked" rather than "unknown"
 * (BR-25).
 *
 * `verifyCode` is the long random value minted with the certificate; it is a
 * public verification token, never an identifier that could be guessed by
 * counting (BR-25).
 *
 * @see BR-25, BR-26 · PRD §9.17 · CONSTITUTION art. 13 #11
 */
final class CertificateRow extends ViewModel
{
    use PresentsFormValues;

    public static function from(Certificate $certificate): self
    {
        $holder = self::related($certificate, 'user');
        $profile = self::related($holder, 'profile');

        $revoked = $certificate->getAttribute('revoked_at') !== null;

        return new self([
            'id' => (string) $certificate->getKey(),
            'holderName' => (string) (
                $profile?->getAttribute('full_name_ar')
                ?? self::attr($holder, 'email')
                ?? '—'
            ),
            'serialNumber' => (string) $certificate->getAttribute('serial_number'),
            'verifyCode' => (string) $certificate->getAttribute('verify_code'),
            'issuedAt' => $certificate->getAttribute('issued_at'),
            'isRevoked' => $revoked,
            'statusLabel' => $revoked ? __('certificates.status.revoked') : __('certificates.status.issued'),
            'statusVariant' => $revoked ? 'error' : 'success',
            'statusIcon' => $revoked ? 'warn' : 'check',
        ]);
    }
}
