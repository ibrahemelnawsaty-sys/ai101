<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Presenters\Concerns\PresentsVariants;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * The five headline figures on the trainer's cohort report (PRD §4.2, §9.15).
 *
 * The controller used to hand the template an array keyed `participants`,
 * `sessions`, `assignments`, `submissions`, `eligible` while the screen read
 * `averageAttendance`, `attendanceVariant`, `submissionRateVariant` and five
 * more — every card on the report fatalled.
 *
 * Two of the five carry a variant, and both are judged against the cohort's own
 * `min_attendance_rate` rather than a number written into the template: a rate
 * under that threshold is what BR-26 refuses a certificate for, so it must read
 * as critical here even when it clears the generic 85% band (PRD §9.9.6).
 *
 * The completion rate carries no variant on purpose. It measures how far
 * through the programme the cohort is, not how well it is doing, and colouring
 * "week two of four" red would say something untrue.
 *
 * @see BR-11, BR-23, BR-26 · PRD §4.2, §9.9.6, §9.15 · CONSTITUTION art. 5, art. 6, art. 14
 */
final class ReportSummary extends ViewModel
{
    use PresentsVariants;

    public static function of(
        int $participants,
        ?float $averageAttendance,
        ?float $averageScore,
        float $submissionRate,
        float $completionRate,
        float $minimumRate,
    ): self {
        return new self([
            'participants' => $participants,
            'averageAttendance' => self::percent($averageAttendance),
            'attendanceVariant' => $averageAttendance === null
                ? 'default'
                : self::rateVariant($averageAttendance, $minimumRate),
            'averageScore' => self::score($averageScore),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'submissionRate' => self::percent($submissionRate),
            'submissionRateVariant' => self::rateVariant($submissionRate),
            'completionRate' => self::percent($completionRate),
            'minimumRatePercent' => self::percent($minimumRate),
        ]);
    }
}
