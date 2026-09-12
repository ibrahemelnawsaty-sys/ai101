<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\Profile;
use App\Models\Program;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Grading\ScoreCalculator;
use App\Support\QrSvg;
use App\Support\ViewModel;
use Illuminate\Support\Facades\Route;

/**
 * The issued certificate, as its holder sees it (PRD §9.17).
 *
 * `verifyUrl` is built from `verify_code`, which is a long random value and
 * never an account identifier, so the public page it opens cannot be walked by
 * counting (BR-25). That public page shows far less than this screen does, and
 * the share link points at it rather than at anything private.
 *
 * @see BR-25, BR-26 · PRD §9.17 · CONSTITUTION art. 22
 */
final class CertificatePresenter extends ViewModel
{
    public static function from(Certificate $certificate, int $trainingHours): self
    {
        $verifyCode = (string) $certificate->getAttribute('verify_code');
        $verifyUrl = Route::has('certificate.verify')
            ? route('certificate.verify', ['code' => $verifyCode])
            : '';

        return new self([
            'holderNameAr' => self::holderName($certificate, 'full_name_ar'),
            'holderNameEn' => self::holderName($certificate, 'full_name_en'),
            'programName' => self::programName($certificate),
            'cohortName' => self::cohortName($certificate),
            'trainingHours' => $trainingHours,
            'serialNumber' => (string) $certificate->getAttribute('serial_number'),
            'issuedAt' => Present::toDateTime($certificate->getAttribute('issued_at')),
            'finalScore' => Present::decimal($certificate->getAttribute('final_score')),
            'grandTotal' => ScoreCalculator::GRAND_TOTAL,
            'verifyUrl' => $verifyUrl,
            'linkedInShareUrl' => $verifyUrl === ''
                ? ''
                : 'https://www.linkedin.com/sharing/share-offsite/?'.http_build_query(['url' => $verifyUrl]),
            'isRevoked' => $certificate->getAttribute('revoked_at') !== null,
            // The printed certificate carries its verification link as a code,
            // like the card (D-81).
            'qrSvg' => QrSvg::of($verifyUrl),
        ]);
    }

    private static function holderName(Certificate $certificate, string $attribute): string
    {
        if (! $certificate->relationLoaded('user')) {
            return '';
        }

        $user = $certificate->getRelation('user');

        if (! $user instanceof User || ! $user->relationLoaded('profile')) {
            return '';
        }

        $profile = $user->getRelation('profile');

        return $profile instanceof Profile ? (string) $profile->getAttribute($attribute) : '';
    }

    private static function cohortName(Certificate $certificate): string
    {
        $cohort = self::cohort($certificate);

        return $cohort === null ? '' : (string) $cohort->getAttribute('name');
    }

    private static function programName(Certificate $certificate): string
    {
        $cohort = self::cohort($certificate);

        if ($cohort === null || ! $cohort->relationLoaded('program')) {
            return (string) config('athar.program_name');
        }

        $program = $cohort->getRelation('program');

        return $program instanceof Program
            ? (string) $program->getAttribute('name_ar')
            : (string) config('athar.program_name');
    }

    private static function cohort(Certificate $certificate): ?Cohort
    {
        if (! $certificate->relationLoaded('cohort')) {
            return null;
        }

        $cohort = $certificate->getRelation('cohort');

        return $cohort instanceof Cohort ? $cohort : null;
    }
}
