<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Presenters\Concerns\PresentsVariants;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * The five headline figures on the platform reports screen.
 *
 * Each rate arrives with the variant it earns, so the cards say the same thing
 * in colour that they say in text — and every card prints its number, because
 * colour alone never carries meaning (art. 18).
 *
 * These are counts across cohorts, not judgements about a person. Anything that
 * decides whether somebody passed or qualified is asked of the service that
 * owns that decision, on the screen that shows it (art. 6).
 *
 * @see PRD §9.18, §13.2 · BR-27, BR-28
 */
final class ReportSummary extends ViewModel
{
    use PresentsVariants;

    public static function of(
        int $registrations,
        ?float $averageAttendance,
        ?float $averageScore,
        float $submissionRate,
        float $completionRate,
    ): self {
        $attendance = self::percent($averageAttendance);
        $submissions = self::percent($submissionRate);

        return new self([
            'registrations' => $registrations,
            'averageAttendance' => $attendance,
            'attendanceVariant' => $averageAttendance === null
                ? 'default'
                : self::rateVariant((float) $attendance),
            'averageScore' => self::score($averageScore),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'submissionRate' => $submissions,
            'submissionRateVariant' => self::rateVariant((float) $submissions),
            'completionRate' => self::percent($completionRate),
        ]);
    }
}
