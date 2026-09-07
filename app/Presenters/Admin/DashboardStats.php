<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Presenters\Concerns\PresentsVariants;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * The six figures on the administration console (PRD §9.18).
 *
 * The console used to be handed a bare array keyed `users`, `cohorts`,
 * `sessions` … while the template read `activeUsers`, `averageAttendance` and
 * `attendanceVariant`, so every card on the screen fatalled. The six figures
 * the PRD actually names are published here, each already carrying the variant
 * its value earns — the template prints, it does not decide (art. 5).
 *
 * @see PRD §9.18 · BR-26, BR-27 · CONSTITUTION art. 5, art. 6
 */
final class DashboardStats extends ViewModel
{
    use PresentsVariants;

    public static function of(
        int $totalRegistered,
        int $activeUsers,
        ?float $averageAttendance,
        ?float $averageScore,
        int $ungradedSubmissions,
        int $certificatesIssued,
    ): self {
        $attendance = self::percent($averageAttendance);

        return new self([
            'totalRegistered' => $totalRegistered,
            'activeUsers' => $activeUsers,
            'averageAttendance' => $attendance,
            'attendanceVariant' => $averageAttendance === null
                ? 'default'
                : self::rateVariant((float) $attendance),
            'averageScore' => self::score($averageScore),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'ungradedSubmissions' => $ungradedSubmissions,
            'certificatesIssued' => $certificatesIssued,
        ]);
    }
}
