<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Events\CertificateIssued;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkIssueCertificateRequest;
use App\Http\Requests\Admin\IssueCertificateRequest;
use App\Http\Requests\Admin\OverrideCertificateRequest;
use App\Http\Requests\Admin\RevokeCertificateRequest;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\User;
use App\Presenters\Admin\CertificateCandidate;
use App\Presenters\Admin\CertificateCounts;
use App\Presenters\Admin\CertificatePerson;
use App\Presenters\Admin\CertificateRow;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Certificates\SerialNumberGenerator;
use App\Services\Grading\ScoreCalculator;
use App\Services\Notifications\InAppNotifier;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issuing and revoking certificates (PRD §9.17).
 *
 * BR-26 is decided by CertificateEligibility and by nothing else: both the
 * attendance rate and the final score must be met, and passing one does not
 * make up for failing the other. An administrator may override that, but only
 * with a written reason, and the override is recorded as an override — not as
 * an ordinary issue (PROJECT-CONTRACT §8).
 *
 * The serial number and the verification code are minted by
 * SerialNumberGenerator; the code is a long random value and never an
 * identifier that could be guessed by counting (BR-25).
 *
 * @see BR-25, BR-26 · PRD §9.17 · CONSTITUTION Art. 6, Art. 8
 */
final class CertificateController extends Controller
{
    use ExportsCsv;

    private const PER_PAGE = 50;

    /** How many participants one eligibility sweep looks at (art. 19). */
    private const CANDIDATE_LIMIT = 300;

    public function __construct(
        private readonly CertificateEligibility $eligibility,
        private readonly ScoreCalculator $scores,
        private readonly SerialNumberGenerator $serials,
        private readonly AuditLogger $audit,
        private readonly InAppNotifier $notifier,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Certificate::class);

        $cohorts = Cohort::query()->orderByDesc('start_date')->get();
        $cohort = $this->selectedCohort($request, $cohorts);

        $issuedQuery = Certificate::query()
            ->with(['user.profile', 'cohort.program'])
            ->orderByDesc('issued_at');

        if ($cohort !== null) {
            $issuedQuery->where('cohort_id', $cohort->getKey());
        }

        $eligible = $this->eligibleCandidates($cohort);
        $notEligible = $this->ineligiblePeople($cohort);

        return view('admin.certificates', [
            'contextLabel' => $cohort?->getAttribute('name'),
            'counts' => CertificateCounts::of(
                eligible: $eligible->count(),
                issued: (clone $issuedQuery)->whereNull('revoked_at')->count(),
                notEligible: $notEligible->count(),
                revoked: (clone $issuedQuery)->whereNotNull('revoked_at')->count(),
            ),
            'eligible' => $eligible,
            'notEligible' => $notEligible,
            'issued' => $issuedQuery->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(static fn (Certificate $row): CertificateRow => CertificateRow::from($row)),
            'overriding' => $this->overriding($request, $cohort),
            'revoking' => $this->revoking($request),
            'cohortOptions' => Options::fromModels(
                $cohorts,
                static fn (Cohort $item): string => (string) $item->getAttribute('name'),
            ),
            'errorState' => null,
        ]);
    }

    /**
     * The cohort this screen is about: the one asked for, else the one that is
     * currently running or opening.
     *
     * @param  Collection<int, Cohort>  $cohorts
     */
    private function selectedCohort(Request $request, Collection $cohorts): ?Cohort
    {
        $requested = $request->query('cohort');

        if (is_string($requested) && $requested !== '') {
            $match = $cohorts->first(
                static fn (Cohort $cohort): bool => (string) $cohort->getKey() === $requested,
            );

            if ($match instanceof Cohort) {
                return $match;
            }
        }

        $current = $cohorts->first(static fn (Cohort $cohort): bool => in_array(
            $cohort->getAttribute('status')?->value,
            [CohortStatus::Running->value, CohortStatus::Completed->value, CohortStatus::Open->value],
            true,
        ));

        return $current instanceof Cohort ? $current : $cohorts->first();
    }

    /**
     * Active participants of the cohort, capped. The cap is deliberate: this is
     * a working queue, and an uncapped per-person eligibility sweep would blow
     * the query budget (art. 19).
     *
     * @return Collection<int, User>
     */
    private function participants(?Cohort $cohort): Collection
    {
        if ($cohort === null) {
            return collect();
        }

        return User::query()
            ->with('profile')
            ->whereIn(
                'id',
                Enrollment::query()
                    ->where('cohort_id', $cohort->getKey())
                    ->where('role_in_cohort', EnrollmentRole::Participant->value)
                    ->where('status', EnrollmentStatus::Active->value)
                    ->select('user_id'),
            )
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
    }

    /**
     * Everyone who qualifies and holds no live certificate yet (BR-26).
     *
     * @return Collection<int, CertificateCandidate>
     */
    private function eligibleCandidates(?Cohort $cohort): Collection
    {
        if ($cohort === null) {
            return collect();
        }

        $holders = Certificate::query()
            ->where('cohort_id', $cohort->getKey())
            ->whereNull('revoked_at')
            ->pluck('user_id')
            ->all();

        return $this->participants($cohort)
            ->reject(static fn (User $user): bool => in_array($user->getKey(), $holders, true))
            ->filter(fn (User $user): bool => $this->eligibility->isEligible($user, $cohort))
            ->map(fn (User $user): CertificateCandidate => CertificateCandidate::from(
                $user,
                $cohort,
                $this->eligibility,
                $this->scores,
            ))
            ->values();
    }

    /**
     * Everyone who does not qualify, with every reason in full (BR-26).
     *
     * @return Collection<int, CertificatePerson>
     */
    private function ineligiblePeople(?Cohort $cohort): Collection
    {
        if ($cohort === null) {
            return collect();
        }

        return $this->participants($cohort)
            ->reject(fn (User $user): bool => $this->eligibility->isEligible($user, $cohort))
            ->map(fn (User $user): CertificatePerson => CertificatePerson::from(
                $user,
                $cohort,
                $this->eligibility,
                $this->scores,
            ))
            ->values();
    }

    /** The manual-override panel, open on `?override={user}`. */
    private function overriding(Request $request, ?Cohort $cohort): ?CertificatePerson
    {
        $id = $request->query('override');

        if ($cohort === null || ! is_string($id) || $id === '') {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->with('profile')->find($id);

        return $user === null
            ? null
            : CertificatePerson::from($user, $cohort, $this->eligibility, $this->scores);
    }

    /** The revocation panel, open on `?revoke={certificate}`. */
    private function revoking(Request $request): ?CertificateRow
    {
        $id = $request->query('revoke');

        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var Certificate|null $certificate */
        $certificate = Certificate::query()->with('user.profile')->find($id);

        return $certificate === null ? null : CertificateRow::from($certificate);
    }

    /**
     * Issue one certificate. Eligibility is re-evaluated here, at the moment of
     * issue, not read from whatever the screen showed a minute ago (Art. 5).
     */
    public function issue(IssueCertificateRequest $request): RedirectResponse
    {
        $holder = $request->holder();
        $cohort = $request->cohort();

        /** @var User $admin */
        $admin = $request->user();

        if (! $this->eligibility->isEnrolled($holder, $cohort)) {
            return back()->withErrors(['user_id' => __('certificates.errors.not_enrolled')]);
        }

        $eligible = $this->eligibility->isEligible($holder, $cohort);

        if (! $eligible && ! $request->isOverride()) {
            return back()->withErrors([
                'user_id' => __('certificates.errors.not_eligible'),
            ]);
        }

        if ($this->hasLiveCertificate($holder, $cohort)) {
            return back()->withErrors(['user_id' => __('certificates.errors.already_issued')]);
        }

        $this->persist(
            $holder,
            $cohort,
            $admin,
            $eligible ? AuditLogger::CERTIFICATE_ISSUED : AuditLogger::CERTIFICATE_OVERRIDDEN,
            $request->overrideReason(),
        );

        return back()->with('status', __('certificates.issued'));
    }

    /**
     * Revoking stamps `revoked_at`; the row survives so the public verification
     * page can say "revoked" rather than "unknown" (BR-25).
     */
    public function revoke(RevokeCertificateRequest $request, Certificate $certificate): RedirectResponse
    {
        $this->audit->log(
            action: AuditLogger::CERTIFICATE_REVOKED,
            entity: $certificate,
            before: ['revoked_at' => null],
            after: ['reason' => $request->reason()],
        );

        $certificate->forceFill(['revoked_at' => Clock::now()])->save();

        return back()->with('status', __('certificates.revoked'));
    }

    /**
     * Issue to everyone in one cohort who already qualifies.
     *
     * Nothing is forced: each candidate is put through exactly the same
     * eligibility check as a single issue, and anyone who does not qualify is
     * simply skipped. There is no bulk override — an override is a decision
     * about one person and needs a reason of its own (BR-26).
     */
    public function issueBulk(BulkIssueCertificateRequest $request): RedirectResponse
    {
        $cohort = $request->cohort();

        /** @var User $admin */
        $admin = $request->user();

        $issued = 0;

        foreach ($request->candidates() as $holder) {
            if (! $this->eligibility->isEligible($holder, $cohort)) {
                continue;
            }

            if ($this->hasLiveCertificate($holder, $cohort)) {
                continue;
            }

            $this->persist($holder, $cohort, $admin, AuditLogger::CERTIFICATE_ISSUED, null);
            $issued++;
        }

        return back()->with('status', __('certificates.bulk_issued', ['count' => $issued]));
    }

    /**
     * Issue against the rules, on purpose, with a written reason. The entry in
     * the trail says `certificate.overridden`, never `certificate.issued`, so
     * an audit can tell the two apart years later (PROJECT-CONTRACT §8).
     */
    public function override(OverrideCertificateRequest $request, User $user): RedirectResponse
    {
        $holder = $user;
        $cohort = $request->cohort();

        /** @var User $admin */
        $admin = $request->user();

        if (! $this->eligibility->isEnrolled($holder, $cohort)) {
            return back()->withErrors(['cohort_id' => __('certificates.errors.not_enrolled')]);
        }

        if ($this->hasLiveCertificate($holder, $cohort)) {
            return back()->withErrors(['cohort_id' => __('certificates.errors.already_issued')]);
        }

        $this->persist(
            $holder,
            $cohort,
            $admin,
            AuditLogger::CERTIFICATE_OVERRIDDEN,
            $request->reason(),
        );

        return back()->with('status', __('certificates.issued'));
    }

    /**
     * Replace a certificate — a corrected name, a re-generated file. The old
     * one is revoked rather than edited, so the serial that was already handed
     * out keeps meaning exactly what it meant (BR-25).
     */
    public function reissue(Certificate $certificate): RedirectResponse
    {
        $this->authorize('reissue', $certificate);

        $holder = $certificate->user;
        $cohort = $certificate->cohort;

        if (! $holder instanceof User || $cohort === null) {
            return back()->withErrors(['certificate' => __('certificates.errors.not_enrolled')]);
        }

        /** @var User $admin */
        $admin = request()->user();

        $this->audit->log(
            action: AuditLogger::CERTIFICATE_REVOKED,
            entity: $certificate,
            before: ['revoked_at' => null],
            after: ['reason' => 'reissue'],
        );

        $certificate->forceFill(['revoked_at' => Clock::now()])->save();

        $this->persist($holder, $cohort, $admin, AuditLogger::CERTIFICATE_ISSUED, 'reissue');

        return back()->with('status', __('certificates.reissued'));
    }

    /** The register of issued certificates as a CSV. */
    public function export(): Response
    {
        $this->authorize('viewAny', Certificate::class);

        $rows = Certificate::query()
            ->with(['user.profile', 'cohort'])
            ->orderBy('issued_at')
            ->get()
            ->map(static fn (Certificate $certificate): array => [
                (string) $certificate->getAttribute('serial_number'),
                (string) ($certificate->user?->profile?->getAttribute('full_name_ar') ?? ''),
                (string) ($certificate->cohort?->getAttribute('name') ?? ''),
                (string) ($certificate->getAttribute('final_score') ?? ''),
                (string) ($certificate->getAttribute('attendance_rate') ?? ''),
                $certificate->getAttribute('revoked_at') === null ? '0' : '1',
            ])
            ->values()
            ->all();

        return $this->csvResponse([
            __('certificates.export.serial'),
            __('certificates.export.holder'),
            __('certificates.export.cohort'),
            __('certificates.export.score'),
            __('certificates.export.attendance'),
            __('certificates.export.revoked'),
        ], $rows, 'athar-certificates.csv');
    }

    private function hasLiveCertificate(User $holder, Cohort $cohort): bool
    {
        return Certificate::query()
            ->where('user_id', $holder->getKey())
            ->where('cohort_id', $cohort->getKey())
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Mint the serial, write the row and record it — the one place a
     * certificate comes into existence, whichever endpoint asked for it.
     */
    private function persist(
        User $holder,
        Cohort $cohort,
        User $admin,
        string $action,
        ?string $reason,
    ): Certificate {
        $at = Clock::now();

        /** @var Certificate $issued */
        $issued = $this->serials->allocate($cohort, fn (string $serial): Certificate => DB::transaction(function () use ($serial, $holder, $cohort, $admin, $at, $action, $reason): Certificate {
            /** @var Certificate $certificate */
            $certificate = Certificate::query()->create([
                'user_id' => $holder->getKey(),
                'cohort_id' => $cohort->getKey(),
                'serial_number' => $serial,
                'verify_code' => $this->serials->verifyCode(),
                'issued_at' => $at,
                'issued_by' => $admin->getKey(),
                'final_score' => $this->scores->finalScore($holder, $cohort),
                'attendance_rate' => $this->eligibility->attendanceRate($holder, $cohort),
                'revoked_at' => null,
            ]);

            $this->audit->log(
                action: $action,
                entity: $certificate,
                before: null,
                after: ['serial_number' => $serial, 'override_reason' => $reason],
                actor: $admin,
            );

            return $certificate;
        }));

        // After allocation returns, never inside it: a retried allocation
        // discards its serial, and a letter must name the one that was kept.
        // Both channels (PRD §9.16.1); nothing announced a certificate before
        // D-77.
        $this->notifier->notify(
            [(string) $holder->getKey()],
            'certificate_issued',
            (string) __('notifications.types.certificate_issued.title'),
            (string) __('notifications.types.certificate_issued.body', ['serial' => (string) $issued->getAttribute('serial_number')]),
            route('certificate'),
            $at,
        );

        CertificateIssued::dispatch(
            $holder,
            (string) $issued->getAttribute('serial_number'),
            route('certificate'),
            route('certificate.verify', ['code' => (string) $issued->getAttribute('verify_code')]),
        );

        return $issued;
    }
}
