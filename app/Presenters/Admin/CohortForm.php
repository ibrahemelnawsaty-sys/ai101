<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\CohortStatus;
use App\Models\Cohort;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * The cohort editor, open on a new cohort or an existing one.
 *
 * `passScore` and `minAttendanceRate` are the two numbers BR-26 reads when it
 * decides who qualifies for a certificate, so they are edited here and nowhere
 * else. `registrationClosesAtValue` is rendered in Riyadh wall time because
 * that is what the administrator types and what the FormRequest re-reads
 * through Clock; storage stays UTC (art. 11).
 *
 * `exists` is what the template uses to choose between the create and the
 * update endpoint — a server fact, not a guess from an empty id.
 *
 * @see BR-07, BR-26, BR-31 · PRD §4.2, §7.2 · CONSTITUTION art. 11
 */
final class CohortForm extends ViewModel
{
    use PresentsFormValues;

    /** A blank editor for a cohort that does not exist yet. */
    public static function blank(?string $programId = null): self
    {
        return new self([
            'exists' => false,
            'id' => null,
            'name' => '',
            'programId' => $programId,
            'startsAtValue' => '',
            'endsAtValue' => '',
            'registrationClosesAtValue' => '',
            'capacity' => '',
            'passScore' => Cohort::DEFAULT_PASS_SCORE,
            'minAttendanceRate' => Cohort::DEFAULT_MIN_ATTENDANCE_RATE,
            'requiresApproval' => false,
            'status' => CohortStatus::Upcoming->value,
        ]);
    }

    public static function from(Cohort $cohort): self
    {
        $status = $cohort->getAttribute('status');

        return new self([
            'exists' => true,
            'id' => (string) $cohort->getKey(),
            'name' => (string) $cohort->getAttribute('name'),
            'programId' => (string) $cohort->getAttribute('program_id'),
            'startsAtValue' => self::dateInput($cohort->getAttribute('start_date')),
            'endsAtValue' => self::dateInput($cohort->getAttribute('end_date')),
            'registrationClosesAtValue' => self::dateTimeInput($cohort->getAttribute('registration_closes_at')),
            'capacity' => (int) $cohort->getAttribute('capacity'),
            'passScore' => (int) $cohort->getAttribute('pass_score'),
            'minAttendanceRate' => (int) $cohort->getAttribute('min_attendance_rate'),
            'requiresApproval' => (bool) $cohort->getAttribute('requires_approval'),
            'status' => $status instanceof CohortStatus ? $status->value : (string) $status,
        ]);
    }
}
