<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Certificates\CertificateEligibility;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * The dashboard's attendance-rate card.
 *
 * Every number here is asked of CertificateEligibility — the rate, the cohort
 * minimum and whether the minimum is met are BR-26's business, not this
 * class's. What this class decides is the colour, from PRD §9.9.6's bands
 * (art. 5, art. 6).
 *
 * @see BR-08, BR-09, BR-26 · PRD §9.5.3, §9.9.6
 */
final class AttendanceRatePresenter extends ViewModel
{
    /**
     * An account with no cohort yet. Not an error and not a skeleton: zero
     * countable sessions is the empty state the card already words (art. 17).
     */
    public static function none(): self
    {
        return new self([
            'ratePercent' => '0',
            'minimumRatePercent' => '0',
            'meetsMinimum' => false,
            'attendedSessions' => 0,
            'totalSessions' => 0,
            'rateVariant' => 'neutral',
        ]);
    }

    public static function from(
        User $user,
        Cohort $cohort,
        CertificateEligibility $eligibility,
        CarbonImmutable $now,
    ): self {
        $rate = $eligibility->attendanceRate($user, $cohort, $now);
        $minimum = $eligibility->minAttendanceRate($cohort);
        $counts = $eligibility->sessionCounts($user, $cohort, $now);

        return new self([
            'ratePercent' => Present::decimal($rate),
            'minimumRatePercent' => Present::decimal($minimum),
            'meetsMinimum' => $eligibility->meetsAttendance($user, $cohort),
            'attendedSessions' => $counts['attended'],
            'totalSessions' => $counts['total'],
            'rateVariant' => Present::rateVariant($rate, $minimum),
        ]);
    }
}
