<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The two certificate conditions, always shown, met or not (PRD §9.17).
 *
 * BR-26 is answered by CertificateEligibility and only restated here. Both
 * conditions are reported even when only one fails, because a participant who
 * fixes one and is then surprised by the other has been misled — the service
 * returns both reasons for exactly that reason.
 *
 * @see BR-26 · PRD §9.17
 */
final class EligibilityPresenter extends ViewModel
{
    /**
     * An account with no cohort yet: neither condition is met and the screen
     * says so, rather than showing a skeleton that never resolves (art. 17).
     */
    public static function none(): self
    {
        return new self([
            'isEligible' => false,
            'meetsAttendance' => false,
            'meetsScore' => false,
            'attendanceRatePercent' => '0',
            'minimumRatePercent' => '0',
            'finalScore' => '0',
            'passScore' => '0',
            'reasons' => [(string) __('certificates.reasons.not_enrolled')],
        ]);
    }

    /**
     * @param  array{enrolled: bool, attendance_rate: float, required_attendance_rate: float, meets_attendance: bool, final_score: float, required_score: float, meets_score: bool, eligible: bool, reasons: array<string, string>}  $summary
     */
    public static function from(array $summary): self
    {
        return new self([
            'isEligible' => $summary['eligible'],
            'meetsAttendance' => $summary['meets_attendance'],
            'meetsScore' => $summary['meets_score'],
            'attendanceRatePercent' => Present::decimal($summary['attendance_rate']),
            'minimumRatePercent' => Present::decimal($summary['required_attendance_rate']),
            'finalScore' => Present::decimal($summary['final_score']),
            'passScore' => Present::decimal($summary['required_score']),
            'reasons' => array_values($summary['reasons']),
        ]);
    }
}
