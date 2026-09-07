<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * The four counters above the trainer's roster (PRD §8).
 *
 * `averageAttendance` is the mean of the rates CertificateEligibility produced
 * for this cohort — not a second average computed from a second definition of
 * "attended" (art. 6). `atRisk` counts the people under the cohort's own
 * `min_attendance_rate`, which is the threshold BR-26 judges the certificate on
 * and never a number written into a template.
 *
 * @see BR-23, BR-26 · PRD §8, §9.9.6 · CONSTITUTION art. 5, art. 6
 */
final class ParticipantStats extends ViewModel
{
    use PresentsVariants;

    public static function of(
        int $total,
        int $activeCount,
        ?float $averageAttendance,
        float $minimumRate,
        int $atRisk,
    ): self {
        return new self([
            'total' => $total,
            'activeCount' => $activeCount,
            'averageAttendance' => self::percent($averageAttendance),
            'averageAttendanceVariant' => $averageAttendance === null
                ? 'default'
                : self::rateVariant($averageAttendance, $minimumRate),
            'minimumRatePercent' => self::percent($minimumRate),
            'atRisk' => $atRisk,
        ]);
    }
}
