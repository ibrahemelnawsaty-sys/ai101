<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * Somebody who does NOT yet qualify for a certificate, and exactly why.
 *
 * BR-26 is decided by CertificateEligibility and by nothing else: attendance
 * AND score must both be met, and meeting one never compensates for the other.
 * `attendanceMet` and `scoreMet` are that service's two answers, kept apart on
 * purpose so the screen can show which of the two is missing.
 *
 * `reasons` is the service's own Arabic list, carried whole and untruncated —
 * it is the record an administrator reads before deciding to override.
 *
 * @see BR-26 · PRD §9.17, §9.18 · CONSTITUTION art. 6
 */
final class CertificatePerson extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(
        User $user,
        Cohort $cohort,
        CertificateEligibility $eligibility,
        ScoreCalculator $scores,
    ): self {
        $profile = self::related($user, 'profile');

        $rate = $eligibility->attendanceRate($user, $cohort);
        $minimum = $eligibility->minAttendanceRate($cohort);
        $score = $scores->finalScore($user, $cohort);
        $passScore = $scores->passScore($cohort);

        return new self([
            'id' => (string) $user->getKey(),
            'name' => (string) ($profile?->getAttribute('full_name_ar') ?? $user->getAttribute('email')),
            'attendancePercent' => self::percent($rate),
            'attendanceMet' => $eligibility->meetsAttendance($user, $cohort),
            'attendanceVariant' => self::rateVariant($rate, $minimum),
            'minimumRatePercent' => self::percent($minimum),
            'score' => self::score($score),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'scoreMet' => $eligibility->meetsScore($user, $cohort),
            'reasons' => $eligibility->reasons($user, $cohort),
        ]);
    }
}
