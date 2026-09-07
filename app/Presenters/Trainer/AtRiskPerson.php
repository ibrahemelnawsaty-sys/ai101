<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * A participant below the cohort's certificate threshold.
 *
 * The same shape serves the compact list on the attendance screen and the
 * fuller table on the reports screen, so the two can never disagree about who
 * is at risk. `reasons` is CertificateEligibility's own list, carried whole:
 * BR-26 needs both conditions met, and the trainer needs to see which one is
 * missing.
 *
 * @see BR-08, BR-09, BR-23, BR-26 · PRD §9.9.7 · CONSTITUTION art. 6, art. 18
 */
final class AtRiskPerson extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    /**
     * @param  array<string, string>  $reasons
     */
    public static function of(
        User $participant,
        float $ratePercent,
        float $minimumRate,
        ?float $score,
        array $reasons,
    ): self {
        $profile = self::related($participant, 'profile');

        $name = (string) (
            $profile?->getAttribute('full_name_ar') ?? $participant->getAttribute('email')
        );

        $variant = self::rateVariant($ratePercent, $minimumRate);

        return new self([
            'id' => (string) $participant->getKey(),
            'name' => $name,
            'participantName' => $name,
            'ratePercent' => self::percent($ratePercent),
            'attendancePercent' => self::percent($ratePercent),
            'rateVariant' => $variant,
            'attendanceVariant' => $variant,
            'minimumRatePercent' => self::percent($minimumRate),
            'score' => self::score($score),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
            'reasons' => array_values($reasons),
        ]);
    }
}
