<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Cohort;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Support\ViewModel;

/**
 * Somebody who has met both certificate conditions but holds no certificate yet.
 *
 * The rate and the score are asked of the services that own them —
 * CertificateEligibility for BR-26 and ScoreCalculator for BR-11 — so this row
 * and the certificate itself can never disagree (art. 6). Nothing here decides
 * eligibility; the caller has already asked the service that does.
 *
 * @see BR-11, BR-26 · PRD §9.17, §9.18 · CONSTITUTION art. 6
 */
final class CertificateCandidate extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(
        User $user,
        Cohort $cohort,
        CertificateEligibility $eligibility,
        ScoreCalculator $scores,
    ): self {
        $profile = self::related($user, 'profile');
        $rate = $eligibility->attendanceRate($user, $cohort);
        $score = $scores->finalScore($user, $cohort);

        return new self([
            'id' => (string) $user->getKey(),
            'name' => $profile?->getAttribute('full_name_ar') ?? (string) $user->getAttribute('email'),
            'cohortName' => (string) $cohort->getAttribute('name'),
            'attendancePercent' => self::percent($rate),
            'score' => self::score($score),
            'scoreMax' => ScoreCalculator::GRAND_TOTAL,
        ]);
    }
}
