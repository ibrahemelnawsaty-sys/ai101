<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\Certificates\SerialNumberGenerator;
use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;

/**
 * Public verification of a certificate.
 *
 * The page shows exactly five things — first name, family name, programme,
 * cohort, issue date — plus the status, and nothing else (BR-25). The code in
 * the URL is a long signed random value generated with the certificate; it is
 * never the holder's identifier, so the page cannot be walked by counting.
 *
 * A malformed code is refused before any query runs, so the lookup surface is
 * not a probing tool.
 *
 * @see BR-25, BR-26 · PRD §9.17, §12.6 · PROJECT-CONTRACT §8 · CONSTITUTION Art. 7
 */
final class CertificateVerificationController extends Controller
{
    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'certificate';

    public function __construct(
        private readonly RiyadhFormatter $formatter,
        private readonly SerialNumberGenerator $serials,
    ) {}

    public function show(string $code): View
    {
        $certificate = $this->find($code);

        if ($certificate === null) {
            return view('public.certificate-verify', [
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
                'certificate' => [],
                'checkedAt' => $this->formatter->dateTime(Clock::now()),
            ]);
        }

        return view('public.certificate-verify', [
            'screen' => self::SCREEN,
            'screenState' => ScreenState::NORMAL,
            'certificate' => $this->publicFacts($certificate),
            'checkedAt' => $this->formatter->dateTime(Clock::now()),
        ]);
    }

    private function find(string $code): ?Certificate
    {
        $code = trim($code);

        if ($code === '' || ! $this->serials->isWellFormedVerifyCode($code)) {
            return null;
        }

        /** @var Certificate|null $certificate */
        $certificate = Certificate::query()
            ->with(['user.profile', 'cohort.program'])
            ->where('verify_code', $code)
            ->first();

        return $certificate;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicFacts(Certificate $certificate): array
    {
        $profile = $certificate->user?->profile;
        $cohort = $certificate->cohort;
        $issuedAt = $certificate->getAttribute('issued_at');

        return [
            'first_name' => $profile?->getAttribute('first_name_ar') ?? '',
            'family_name' => $profile?->getAttribute('last_name_ar') ?? '',
            'program' => $cohort?->program?->getAttribute('name_ar') ?? '',
            'cohort' => $cohort?->getAttribute('name') ?? '',
            'serial_number' => (string) $certificate->getAttribute('serial_number'),
            'issued_at' => $issuedAt === null ? '' : $this->formatter->date($issuedAt),
            'is_revoked' => $certificate->getAttribute('revoked_at') !== null,
        ];
    }
}
