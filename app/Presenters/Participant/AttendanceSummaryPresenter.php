<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\AttendanceStatus;
use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Certificates\CertificateEligibility;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * The circular rate indicator and the six-line count beneath it (PRD §9.9.6).
 *
 * The rate, the cohort minimum and the per-status counts are all asked of
 * CertificateEligibility, which owns the counting rule BR-26 judges on. The
 * colour band is the only thing decided here, and it is PRD §9.9.6's:
 * green above 85, burnt orange between 70 and 85, red below 70.
 *
 * `rateVariant` is the ring's CSS suffix — ok · warn · bad — because the ring
 * is drawn by .arate--* in resources/css/screens.css and not by a pill.
 *
 * @see BR-08, BR-09, BR-26 · PRD §9.9.6
 */
final class AttendanceSummaryPresenter extends ViewModel
{
    /**
     * An account with no cohort yet. Zero countable sessions is exactly the
     * empty state the card already words, so it is shown rather than a skeleton
     * that would never resolve (art. 17).
     */
    public static function none(): self
    {
        return new self([
            'totalSessions' => 0,
            'ratePercent' => '0',
            'rateVariant' => 'ok',
            'minimumRatePercent' => '0',
            'presentCount' => 0,
            'lateCount' => 0,
            'absentCount' => 0,
            'excusedCount' => 0,
            'incompleteCount' => 0,
            'isNearMinimum' => false,
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
        $counts = $eligibility->statusCounts($user, $cohort, $now);
        $sessions = $eligibility->sessionCounts($user, $cohort, $now);

        return new self([
            'totalSessions' => $sessions['total'],
            'ratePercent' => Present::decimal($rate),
            'rateVariant' => Present::rateRing($rate, $minimum),
            'minimumRatePercent' => Present::decimal($minimum),
            'presentCount' => $counts[AttendanceStatus::Present->value] ?? 0,
            'lateCount' => $counts[AttendanceStatus::Late->value] ?? 0,
            'absentCount' => $counts[AttendanceStatus::Absent->value] ?? 0,
            'excusedCount' => $counts[AttendanceStatus::Excused->value] ?? 0,
            'incompleteCount' => $counts[AttendanceStatus::Incomplete->value] ?? 0,
            // PRD §9.9.6 asks for a prominent notice as the rate approaches the
            // certificate minimum but names no distance; the margin is declared
            // in Present and in DECISIONS.md rather than assumed silently.
            'isNearMinimum' => $sessions['total'] > 0 && Present::isNearMinimum($rate, $minimum),
        ]);
    }
}
