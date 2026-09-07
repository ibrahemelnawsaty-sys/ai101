<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * One of an account's enrolments, on its profile page.
 *
 * The rate and the score shown here are the denormalised figures on the
 * enrolment row itself, and `hasAttendance` / `hasScore` say plainly when there
 * is nothing recorded yet — a zero and an absence are different facts, and the
 * template must not have to tell them apart (art. 17).
 *
 * @see BR-11, BR-26 · PRD §4.4, §9.18
 */
final class UserEnrollmentRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(Enrollment $enrollment): self
    {
        $cohort = self::related($enrollment, 'cohort');
        $program = self::related($cohort, 'program');

        $role = $enrollment->getAttribute('role_in_cohort');
        $role = $role instanceof EnrollmentRole ? $role : null;

        $status = $enrollment->getAttribute('status');
        $status = $status instanceof EnrollmentStatus ? $status : null;

        $rate = $enrollment->getAttribute('attendance_rate');
        $score = $enrollment->getAttribute('final_score');

        return new self([
            'cohortName' => self::text($cohort, 'name'),
            'programName' => self::text($program, 'name_ar'),
            'roleLabel' => $role?->label() ?? '—',
            'hasAttendance' => $rate !== null,
            'attendancePercent' => self::percent($rate === null ? null : (float) $rate),
            'hasScore' => $score !== null,
            'score' => self::score($score === null ? null : (float) $score),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'statusLabel' => $status?->label() ?? '—',
            'statusVariant' => self::enrollmentVariantOf($status),
        ]);
    }
}
