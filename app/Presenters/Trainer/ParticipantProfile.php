<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Http\Middleware\EnsureCohortScope;
use App\Models\Enrollment;
use App\Models\User;
use App\Presenters\Concerns\PresentsPeople;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The full profile panel a trainer opens from their roster (PRD §4.2, §4.5.1).
 *
 * PRD §4.2 grants a trainer the full profile of a participant *in their own
 * cohorts*. The scoping is the query's and the policy's job; this view-model
 * simply carries what that panel prints, and deliberately carries nothing else
 * — no password state, no login history, no other cohort's figures.
 *
 * Every number arrives from the service that owns it: the attendance rate and
 * the attended/total pair from CertificateEligibility (BR-26), the score from
 * ScoreCalculator (BR-11), the journey percentage and current step from
 * JourneyEvaluator (BR-21). Nothing here recomputes any of them.
 *
 * @see BR-11, BR-21, BR-22, BR-23, BR-26 · PRD §4.2, §4.5.1, §8 · CONSTITUTION art. 6, art. 22
 */
final class ParticipantProfile extends ViewModel
{
    use PresentsPeople;
    use PresentsVariants;

    /**
     * @param  Collection<int, ParticipantSubmission>  $submissions
     */
    public static function of(
        User $participant,
        Enrollment $enrollment,
        float $ratePercent,
        float $minimumRate,
        int $attendedSessions,
        int $totalSessions,
        float $journeyPercent,
        string $currentStepTitle,
        float $score,
        float $passScore,
        Collection $submissions,
    ): self {
        return new self([
            'id' => (string) $participant->getKey(),
            'participantId' => (string) $participant->getKey(),
            'name' => self::personName($participant),
            'participantName' => self::personName($participant),
            'fullNameAr' => self::personNameAr($participant),
            'fullNameEn' => self::personNameEn($participant),
            'email' => self::personEmail($participant),
            'phone' => self::personPhone($participant),
            'enrolledAt' => $enrollment->getAttribute('enrolled_at'),
            // The attendance screen in the cohort this profile was opened in.
            // It passed a `participant` key nothing reads, and no cohort.
            'attendanceHref' => route('trainer.attendance', [
                EnsureCohortScope::QUERY_KEY => (string) $enrollment->getAttribute('cohort_id'),
            ]),

            'attendancePercent' => self::percent($ratePercent),
            'attendanceVariant' => self::rateVariant($ratePercent, $minimumRate),
            'attendedSessions' => $attendedSessions,
            'totalSessions' => $totalSessions,

            'journeyPercent' => self::percent($journeyPercent),
            'currentStepTitle' => $currentStepTitle,

            'score' => self::score($score),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'maxScore' => ScoreCalculator::GRAND_TOTAL,
            'passScore' => self::score($passScore),
            'isGraded' => $submissions->contains(
                static fn (ParticipantSubmission $row): bool => (bool) $row->isGraded,
            ),

            'submissions' => $submissions,
        ]);
    }
}
