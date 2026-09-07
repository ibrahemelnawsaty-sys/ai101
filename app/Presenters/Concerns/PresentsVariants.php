<?php

declare(strict_types=1);

namespace App\Presenters\Concerns;

use App\Enums\AssignmentStatus;
use App\Enums\AttendanceStatus;
use App\Enums\CohortStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ProgramStatus;
use App\Enums\SessionStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;

/**
 * Where a number or a state becomes a colour and an icon.
 *
 * The thresholds live here and only here: a rate of 92 is `success`, a rate of
 * 74 against a minimum of 75 is `error`. A view that decided this for itself
 * would be a second place the rule lives (CONSTITUTION art. 5, art. 6), and a
 * controller that decided it inline would put the same `if` in eleven files.
 *
 * The vocabulary is deliberately the intersection of what the UI components
 * accept — `success` · `warning` · `error` · `default` — so one variant string
 * is valid on a pill, a stat card and a progress bar alike. There is no yellow
 * or gold anywhere in it: `warning` is the burnt orange #C97A17 defined in
 * resources/css (CONSTITUTION art. 14).
 *
 * Every variant is paired with an icon, because colour alone never carries
 * meaning (CONSTITUTION art. 18).
 *
 * @see PRD §9.9.6, §9.15, §9.17 · BR-08, BR-09, BR-26 · CONSTITUTION art. 5, art. 6, art. 14, art. 18
 */
trait PresentsVariants
{
    /** At or above this, an attendance or completion rate is healthy (PRD §9.9.6). */
    public static int $rateHealthyAt = 85;

    /** Below this, a rate is critical regardless of the cohort minimum (PRD §9.9.6). */
    public static int $rateCriticalBelow = 70;

    /**
     * A percentage rate — attendance, submission, completion — as a variant.
     *
     * `$minimum` is the cohort's own `min_attendance_rate` when the rate is an
     * attendance rate: falling under it is always critical, whatever the
     * generic band would have said (BR-26).
     */
    protected static function rateVariant(?float $percent, ?float $minimum = null): string
    {
        if ($percent === null) {
            return 'default';
        }

        if ($minimum !== null && $percent < $minimum) {
            return 'error';
        }

        if ($percent >= self::$rateHealthyAt) {
            return 'success';
        }

        return $percent >= self::$rateCriticalBelow ? 'warning' : 'error';
    }

    /**
     * A score out of a maximum, judged against the cohort's pass score.
     */
    protected static function scoreVariant(?float $score, ?float $max, ?float $passScore = null): string
    {
        if ($score === null || $max === null || $max <= 0.0) {
            return 'default';
        }

        if ($passScore !== null && $score < $passScore) {
            return 'error';
        }

        return self::rateVariant($score / $max * 100);
    }

    /** The icon that must accompany a rate variant (art. 18). */
    protected static function rateIcon(string $variant): string
    {
        return match ($variant) {
            'success' => 'check',
            'warning', 'error' => 'warn',
            default => 'chart',
        };
    }

    /** A percentage, rounded the way every screen prints it. */
    protected static function percent(?float $value): int
    {
        return $value === null ? 0 : (int) round(max(0.0, min(100.0, $value)));
    }

    /** A score, kept to one decimal and printed without a trailing zero. */
    protected static function score(?float $value): string
    {
        if ($value === null) {
            return '0';
        }

        $printed = number_format($value, 2, '.', '');
        $printed = rtrim(rtrim($printed, '0'), '.');

        return $printed === '' || $printed === '-' ? '0' : $printed;
    }

    // ------------------------------------------------------------- states

    protected static function attendanceVariantOf(?AttendanceStatus $status): string
    {
        return match ($status) {
            AttendanceStatus::Present => 'success',
            AttendanceStatus::Late, AttendanceStatus::Incomplete => 'warning',
            AttendanceStatus::Excused => 'info',
            AttendanceStatus::Absent => 'error',
            default => 'neutral',
        };
    }

    protected static function attendanceIconOf(?AttendanceStatus $status): string
    {
        return match ($status) {
            AttendanceStatus::Present => 'check',
            AttendanceStatus::Late, AttendanceStatus::Incomplete => 'clock',
            AttendanceStatus::Excused => 'shield',
            AttendanceStatus::Absent => 'warn',
            default => 'user',
        };
    }

    protected static function sessionVariantOf(?SessionStatus $status): string
    {
        return match ($status) {
            SessionStatus::Completed => 'success',
            SessionStatus::Live => 'live',
            SessionStatus::Cancelled => 'error',
            SessionStatus::Scheduled => 'info',
            default => 'neutral',
        };
    }

    protected static function sessionIconOf(?SessionStatus $status): string
    {
        return match ($status) {
            SessionStatus::Completed => 'check',
            SessionStatus::Live => 'clock',
            SessionStatus::Cancelled => 'warn',
            default => 'cal',
        };
    }

    protected static function cohortVariantOf(?CohortStatus $status): string
    {
        return match ($status) {
            CohortStatus::Running => 'success',
            CohortStatus::Open => 'info',
            CohortStatus::Completed => 'neutral',
            CohortStatus::Upcoming => 'warning',
            default => 'neutral',
        };
    }

    protected static function cohortIconOf(?CohortStatus $status): string
    {
        return match ($status) {
            CohortStatus::Running => 'clock',
            CohortStatus::Open => 'users',
            CohortStatus::Completed => 'check',
            default => 'cal',
        };
    }

    protected static function programVariantOf(?ProgramStatus $status): string
    {
        return match ($status) {
            ProgramStatus::Published => 'success',
            ProgramStatus::Draft => 'warning',
            ProgramStatus::Archived => 'neutral',
            default => 'neutral',
        };
    }

    protected static function programIconOf(?ProgramStatus $status): string
    {
        return match ($status) {
            ProgramStatus::Published => 'check',
            ProgramStatus::Draft => 'file',
            default => 'folder',
        };
    }

    protected static function userStatusVariantOf(?UserStatus $status): string
    {
        return match ($status) {
            UserStatus::Active => 'success',
            UserStatus::Pending => 'warning',
            UserStatus::Suspended, UserStatus::Deleted => 'error',
            default => 'neutral',
        };
    }

    protected static function userStatusIconOf(?UserStatus $status): string
    {
        return match ($status) {
            UserStatus::Active => 'check',
            UserStatus::Pending => 'clock',
            UserStatus::Suspended, UserStatus::Deleted => 'lock',
            default => 'user',
        };
    }

    protected static function roleVariantOf(?UserRole $role): string
    {
        return match ($role) {
            UserRole::Admin => 'brand',
            UserRole::Trainer => 'info',
            UserRole::Participant => 'neutral',
            default => 'neutral',
        };
    }

    protected static function enrollmentVariantOf(?EnrollmentStatus $status): string
    {
        return match ($status) {
            EnrollmentStatus::Active => 'success',
            EnrollmentStatus::Pending => 'warning',
            EnrollmentStatus::Completed => 'info',
            EnrollmentStatus::Withdrawn => 'error',
            default => 'neutral',
        };
    }

    protected static function enrollmentIconOf(?EnrollmentStatus $status): string
    {
        return match ($status) {
            EnrollmentStatus::Active => 'check',
            EnrollmentStatus::Pending => 'clock',
            EnrollmentStatus::Completed => 'badge',
            EnrollmentStatus::Withdrawn => 'warn',
            default => 'user',
        };
    }

    protected static function assignmentVariantOf(?AssignmentStatus $status): string
    {
        return match ($status) {
            AssignmentStatus::Published => 'success',
            AssignmentStatus::Draft => 'neutral',
            default => 'neutral',
        };
    }

    protected static function submissionVariantOf(?SubmissionStatus $status): string
    {
        return match ($status) {
            SubmissionStatus::Graded => 'success',
            SubmissionStatus::UnderReview => 'info',
            SubmissionStatus::Submitted => 'warning',
            default => 'neutral',
        };
    }

    protected static function submissionIconOf(?SubmissionStatus $status): string
    {
        return match ($status) {
            SubmissionStatus::Graded => 'check',
            SubmissionStatus::UnderReview => 'clock',
            SubmissionStatus::Submitted => 'file',
            default => 'file',
        };
    }
}
