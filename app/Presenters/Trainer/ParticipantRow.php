<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\User;
use App\Presenters\Concerns\PresentsPeople;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * One line of the trainer's roster (PRD §8, /trainer/participants).
 *
 * The controller used to hand the template a paginator of Enrollment models
 * while it read `$row->attendancePercent`, `$row->stateVariant` and eleven more
 * — every row fatalled. Each of those is decided here, from numbers the
 * services already produced: the attendance rate comes from
 * CertificateEligibility in one query for the whole page, the score from
 * ScoreCalculator, and the submitted/total counts from two aggregate queries
 * the controller runs once (art. 19).
 *
 * `hasScore` exists so the template can print an em dash for a participant who
 * has been graded on nothing yet, without asking whether a float is zero — a
 * genuine zero and "not graded" are different facts.
 *
 * @see BR-22, BR-23, BR-26 · PRD §4.2, §8 · CONSTITUTION art. 5, art. 6, art. 18
 */
final class ParticipantRow extends ViewModel
{
    use PresentsPeople;
    use PresentsVariants;

    public static function from(
        Enrollment $enrollment,
        float $ratePercent,
        float $minimumRate,
        ?float $score,
        int $submittedCount,
        int $assignmentsCount,
    ): self {
        $user = self::related($enrollment, 'user');
        $status = $enrollment->getAttribute('status');
        $status = $status instanceof EnrollmentStatus ? $status : null;

        return new self([
            'id' => $user instanceof User ? (string) $user->getKey() : (string) $enrollment->getKey(),
            'participantId' => $user instanceof User ? (string) $user->getKey() : null,
            'name' => self::personName($user),
            'participantName' => self::personName($user),
            'email' => self::personEmail($user),
            'attendancePercent' => self::percent($ratePercent),
            'ratePercent' => self::percent($ratePercent),
            'attendanceVariant' => self::rateVariant($ratePercent, $minimumRate),
            'rateVariant' => self::rateVariant($ratePercent, $minimumRate),
            'submittedCount' => $submittedCount,
            'assignmentsCount' => $assignmentsCount,
            'hasScore' => $score !== null,
            'score' => self::score($score),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'stateLabel' => $status?->label() ?? '—',
            'stateVariant' => self::enrollmentVariantOf($status),
            'stateIcon' => self::enrollmentIconOf($status),
        ]);
    }
}
