<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\SessionStatus;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Participant\CertificatePresenter;
use App\Presenters\Participant\EligibilityPresenter;
use App\Presenters\Participant\TvtcPresenter;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Certificates\CertificateEligibility;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The participant's own certificate (PRD §9.17).
 *
 * BR-26 is applied by CertificateEligibility and stated plainly on the page:
 * both conditions must be met and neither compensates for the other. When the
 * certificate has not been earned the screen says exactly which condition is
 * missing, using the service's own reasons — no threshold is recomputed here.
 *
 * The file is streamed off the private disk after the policy check; its stored
 * path is never rendered (PRD §12.5).
 *
 * @see BR-25, BR-26 · PRD §9.17, §12.5 · CONSTITUTION Art. 6, Art. 22
 */
final class CertificateController extends Controller
{
    use ResolvesActiveCohort;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'certificate';

    public function __construct(
        private readonly CertificateEligibility $eligibility,
        private readonly AttendanceWindow $window,
    ) {}

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $cohort = $this->activeCohort($user);

        if ($cohort === null) {
            return view('participant.certificate', [
                'certificate' => null,
                'eligibility' => EligibilityPresenter::none(),
                'tvtc' => TvtcPresenter::from(null),
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $certificate = Certificate::query()
            ->with(['cohort.program', 'user.profile'])
            ->where('user_id', $user->getKey())
            ->where('cohort_id', $cohort->getKey())
            ->whereNull('revoked_at')
            ->first();

        return view('participant.certificate', [
            'certificate' => $certificate === null
                ? null
                : CertificatePresenter::from($certificate, $this->trainingHours($cohort)),
            'eligibility' => EligibilityPresenter::from($this->eligibility->summary($user, $cohort)),
            'tvtc' => TvtcPresenter::from($certificate),
            'errorState' => null,
            'screen' => self::SCREEN,
            // Until the certificate is issued the screen shows the eligibility
            // checklist, which is this screen's empty state (PRD §9.17).
            'screenState' => ScreenState::of($certificate === null),
        ]);
    }

    /**
     * The programme's taught hours, summed from its own timetable.
     *
     * PRD §9.17 puts a number of training hours on the certificate, and no
     * column records one (PROJECT-CONTRACT §4), so it is derived from the
     * sessions that were actually scheduled and not cancelled — the same
     * timetable everything else on the platform is measured against. It is
     * declared in the batch report as a derivation, not a stored fact.
     */
    private function trainingHours(Cohort $cohort): int
    {
        $seconds = 0;

        $sessions = Session::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->get(['id', 'date', 'start_time', 'end_time', 'status']);

        foreach ($sessions as $session) {
            $seconds += max(
                0,
                $this->window->endsAt($session)->getTimestamp() - $this->window->startsAt($session)->getTimestamp(),
            );
        }

        return (int) round($seconds / 3600);
    }

    public function download(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $certificate = $this->ownCertificate($user);

        $this->authorize('download', $certificate);

        $path = $certificate->getAttribute('file_url');

        if (! is_string($path) || $path === '' || ! Storage::disk('generated')->exists($path)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return Storage::disk('generated')->download(
            $path,
            (string) $certificate->getAttribute('serial_number').'.pdf'
        );
    }

    /**
     * The accredited copy issued by the training authority, when the centre has
     * attached one. It lives on the same private disk and is fetched the same
     * way; there is no second permission model for it.
     */
    public function accredited(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $certificate = $this->ownCertificate($user);

        $this->authorize('download', $certificate);

        $path = $certificate->getAttribute('tvtc_file_url');

        if (! is_string($path) || $path === '' || ! Storage::disk('generated')->exists($path)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return Storage::disk('generated')->download($path);
    }

    /**
     * The signed-in account's own certificate, or 404. Bound to the account, so
     * there is nothing in the URL to tamper with (BR-22).
     */
    private function ownCertificate(User $user): Certificate
    {
        /** @var Certificate|null $certificate */
        $certificate = Certificate::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->orderByDesc('issued_at')
            ->first();

        if ($certificate === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return $certificate;
    }
}
