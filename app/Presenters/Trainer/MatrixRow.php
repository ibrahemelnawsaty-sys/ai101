<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\AttendanceStatus;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One participant's line across every session of the cohort.
 *
 * `ratePercent` is NOT derived from the cells: it is the rate
 * CertificateEligibility computed, because that service owns the counting rule
 * BR-26 reads, and a matrix that counted its own cells would eventually
 * disagree with the certificate (art. 6).
 *
 * @see BR-08, BR-09, BR-23, BR-26 · PRD §9.9.7 · CONSTITUTION art. 6
 */
final class MatrixRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    /**
     * @param  array<int, string>  $sessionIds  in the matrix's column order
     * @param  array<string, AttendanceStatus>  $statuses  session id => status
     */
    public static function of(
        User $participant,
        array $sessionIds,
        array $statuses,
        float $ratePercent,
        float $minimumRate,
    ): self {
        $profile = self::related($participant, 'profile');

        $cells = [];

        foreach ($sessionIds as $sessionId) {
            $cells[] = MatrixCell::of($statuses[$sessionId] ?? null);
        }

        return new self([
            'participantId' => (string) $participant->getKey(),
            'participantName' => (string) (
                $profile?->getAttribute('full_name_ar') ?? $participant->getAttribute('email')
            ),
            'cells' => $cells,
            'ratePercent' => self::percent($ratePercent),
            'rateVariant' => self::rateVariant($ratePercent, $minimumRate),
        ]);
    }
}
