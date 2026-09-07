<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Support\ViewModel;

/**
 * The four counters above the certificate screen: who qualifies, how many were
 * issued, who does not qualify, and how many were withdrawn.
 *
 * @see BR-25, BR-26 · PRD §9.17, §9.18
 */
final class CertificateCounts extends ViewModel
{
    public static function of(int $eligible, int $issued, int $notEligible, int $revoked): self
    {
        return new self([
            'eligible' => $eligible,
            'issued' => $issued,
            'notEligible' => $notEligible,
            'revoked' => $revoked,
        ]);
    }
}
