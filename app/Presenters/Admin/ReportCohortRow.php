<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Cohort;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * One cohort's line on the platform report table.
 *
 * The figures are handed in already aggregated, by grouped queries rather than
 * a per-row calculation: a report over every cohort that asked a service per
 * participant would issue hundreds of queries for one page (art. 19).
 *
 * @see PRD §9.18 · BR-27, BR-28 · CONSTITUTION art. 19
 */
final class ReportCohortRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(
        Cohort $cohort,
        int $participants,
        ?float $averageAttendance,
        ?float $averageScore,
        float $submissionRate,
        float $completionRate,
    ): self {
        $program = self::related($cohort, 'program');

        return new self([
            'id' => (string) $cohort->getKey(),
            'cohortName' => (string) $cohort->getAttribute('name'),
            'programName' => self::text($program, 'name_ar'),
            'participants' => $participants,
            'averageAttendance' => self::percent($averageAttendance),
            'attendanceVariant' => $averageAttendance === null
                ? 'default'
                : self::rateVariant($averageAttendance),
            'averageScore' => self::score($averageScore),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'submissionRate' => self::percent($submissionRate),
            'completionRate' => self::percent($completionRate),
        ]);
    }
}
