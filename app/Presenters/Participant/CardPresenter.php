<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\EnrollmentRole;
use App\Models\Cohort;
use App\Models\DigitalCard;
use App\Models\Profile;
use App\Models\Program;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Support\ViewModel;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Route;

/**
 * The digital participant card (PRD §9.6).
 *
 * The QR is rendered on the server as an inline SVG — no client-side library
 * and no external image host — and it encodes /verify/{token}, where the token
 * is the card's long random value and never the account id, so the public
 * verification page cannot be walked by counting (BR-25).
 *
 * `verifyUrl` is printed in full beside the copy button. It used to be masked
 * down to six characters of the token, on the reasoning that "the full token
 * belongs on the clipboard, not in a screenshot" — but that reasoning does not
 * survive the screen it was written for: the QR sits a few centimetres away on
 * the same screen and encodes the very same URL in machine-readable form, so a
 * screenshot never lost anything to the mask. What the mask did cost was real —
 * with the copy button dead (D-55, D-58) there was no way left to obtain the
 * link at all, and no way to check by eye that the link was the right one.
 *
 * The link is public by design (BR-25): it proves a card is genuine and reveals
 * nothing that the card itself does not already show.
 *
 * @see BR-22, BR-25 · PRD §9.6 · CONSTITUTION art. 22 · D-58
 */
final class CardPresenter extends ViewModel
{
    private const QR_SIZE = 180;

    public static function missing(): self
    {
        return new self([
            'isMissing' => true,
            'programName' => (string) config('athar.program_name'),
            'cohortName' => '',
            'fullNameAr' => '',
            'fullNameEn' => '',
            'photoUrl' => null,
            'roleLabel' => EnrollmentRole::Participant->label(),
            'number' => '',
            'issuedAt' => null,
            'expiresAt' => null,
            'qrSvg' => '',
            'verifyUrl' => '',
            'statusLabel' => '',
            'statusVariant' => 'neutral',
            'statusIcon' => 'card',
            'scanCount' => 0,
        ]);
    }

    public static function from(DigitalCard $card): self
    {
        $token = (string) $card->getAttribute('qr_token');
        $verifyUrl = Route::has('card.verify') ? route('card.verify', ['token' => $token]) : '';
        $isRevoked = $card->getAttribute('revoked_at') !== null;
        $profile = self::profile($card);
        $cohort = self::cohort($card);

        return new self([
            'isMissing' => false,
            'programName' => self::programName($cohort),
            'cohortName' => $cohort === null ? '' : (string) $cohort->getAttribute('name'),
            'fullNameAr' => $profile === null ? '' : (string) $profile->getAttribute('full_name_ar'),
            'fullNameEn' => $profile === null ? '' : (string) $profile->getAttribute('full_name_en'),
            'photoUrl' => $profile === null ? null : Present::text($profile->getAttribute('avatar_url')),
            'roleLabel' => EnrollmentRole::Participant->label(),
            'number' => (string) $card->getAttribute('card_number'),
            'issuedAt' => Present::toDateTime($card->getAttribute('issued_at')),
            'expiresAt' => $cohort === null
                ? null
                : Present::toDateTime($cohort->getAttribute('end_date')),
            'qrSvg' => self::qrSvg($verifyUrl),
            'verifyUrl' => $verifyUrl,
            'statusLabel' => (string) __($isRevoked ? 'card.state.revoked' : 'card.state.valid'),
            'statusVariant' => $isRevoked ? 'error' : 'success',
            'statusIcon' => $isRevoked ? 'warn' : 'check',
            // PRD §9.6 words the scan counter as a statistic only. No column
            // records it yet (PROJECT-CONTRACT §4), so the honest answer is
            // zero rather than an invented number — see the batch report.
            'scanCount' => 0,
        ]);
    }

    /**
     * An inline SVG with no XML declaration, so it can be dropped straight into
     * the document. A writer failure must not take the card down with it: the
     * card is still a valid identity without its code (art. 7, art. 17).
     */
    private static function qrSvg(string $url): string
    {
        if ($url === '') {
            return '';
        }

        try {
            return Builder::create()
                ->writer(new SvgWriter)
                ->writerOptions([
                    SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
                    SvgWriter::WRITER_OPTION_EXCLUDE_SVG_WIDTH_AND_HEIGHT => true,
                ])
                ->data($url)
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size(self::QR_SIZE)
                ->margin(0)
                ->build()
                ->getString();
        } catch (\Throwable) {
            return '';
        }
    }

    private static function profile(DigitalCard $card): ?Profile
    {
        if (! $card->relationLoaded('user')) {
            return null;
        }

        $user = $card->getRelation('user');

        if (! $user instanceof User || ! $user->relationLoaded('profile')) {
            return null;
        }

        $profile = $user->getRelation('profile');

        return $profile instanceof Profile ? $profile : null;
    }

    private static function cohort(DigitalCard $card): ?Cohort
    {
        if (! $card->relationLoaded('cohort')) {
            return null;
        }

        $cohort = $card->getRelation('cohort');

        return $cohort instanceof Cohort ? $cohort : null;
    }

    private static function programName(?Cohort $cohort): string
    {
        if ($cohort === null || ! $cohort->relationLoaded('program')) {
            return (string) config('athar.program_name');
        }

        $program = $cohort->getRelation('program');

        return $program instanceof Program
            ? (string) $program->getAttribute('name_ar')
            : (string) config('athar.program_name');
    }
}
