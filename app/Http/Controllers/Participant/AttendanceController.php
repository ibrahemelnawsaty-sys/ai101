<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\AttendanceStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\CheckInRequest;
use App\Http\Requests\Participant\CheckOutRequest;
use App\Models\Attendance;
use App\Models\Cohort;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Participant\AttendanceRecordPresenter;
use App\Presenters\Participant\AttendanceSummaryPresenter;
use App\Presenters\Participant\AttendanceWindowPresenter;
use App\Presenters\Support\Options;
use App\Services\Attendance\AttendanceRecorder;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The participant's own attendance: the log, and the two buttons.
 *
 * Nothing about the window is decided here. AttendanceWindow answers whether
 * check-in or check-out is open, AttendanceRecorder writes the row, and both
 * read the instant from Clock::now() — the browser never sends a time and a
 * request that carries one is refused outright (BR-07).
 *
 * The two POST endpoints re-evaluate everything on arrival, so a page rendered
 * ten minutes ago cannot buy a late check-in. What the page showed is a
 * reflection of a past decision, never the decision itself (Art. 5).
 *
 * @see BR-01..BR-09, BR-22 · PRD §9.9 · CONSTITUTION Art. 5, Art. 11
 */
final class AttendanceController extends Controller
{
    use ExportsCsv;
    use ResolvesActiveCohort;

    /** Article 19 — any list past fifty rows is paginated. */
    private const PER_PAGE = 30;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'attendance';

    public function __construct(
        private readonly AttendanceWindow $window,
        private readonly AttendanceRecorder $recorder,
        private readonly CertificateEligibility $eligibility,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Attendance::class);

        $cohort = $this->activeCohort($user);
        $now = Clock::now();

        if ($cohort === null) {
            return view('participant.attendance', [
                'serverNow' => $now,
                'window' => AttendanceWindowPresenter::noSession(),
                'records' => new Collection,
                'summary' => AttendanceSummaryPresenter::none(),
                'weekOptions' => [],
                'statusOptions' => $this->statusOptions(),
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $session = $this->currentOrNextSession((string) $cohort->getKey(), $now);

        $records = $this->logQuery($user, (string) $cohort->getKey())
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(static fn (Attendance $row): AttendanceRecordPresenter => AttendanceRecordPresenter::from($row));

        return view('participant.attendance', [
            'serverNow' => $now,
            'window' => $session === null
                ? AttendanceWindowPresenter::noSession()
                : AttendanceWindowPresenter::from(
                    $session,
                    $this->recorder->findRecord($user, $session),
                    $this->window,
                    $now,
                ),
            'records' => $records,
            'summary' => AttendanceSummaryPresenter::from($user, $cohort, $this->eligibility, $now),
            'weekOptions' => $this->weekOptions($cohort),
            'statusOptions' => $this->statusOptions(),
            'errorState' => null,
            'screen' => self::SCREEN,
            // The log is the screen's content: no row recorded yet is the empty
            // state, whatever the check-in panel above it happens to be showing.
            'screenState' => ScreenState::of($records->isEmpty()),
        ]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function weekOptions(Cohort $cohort): array
    {
        return Options::fromModels(
            $cohort->weeks()->orderBy('index')->get(),
            static fn (Model $week): string => (string) $week->getAttribute('title'),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return Options::fromEnum(AttendanceStatus::class);
    }

    /**
     * BR-01, BR-02, BR-03, BR-06. The FormRequest has already refused any
     * client-sent time and the policy has already refused a session that is not
     * this participant's; the recorder decides the rest and raises a domain
     * exception with an Arabic message when it refuses.
     */
    public function checkIn(CheckInRequest $request, Session $session): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->recorder->checkIn(
            $user,
            $session,
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('status', __('attendance.checked_in'));
    }

    /** BR-04, BR-05 — no check-out without a check-in for the same session. */
    public function checkOut(CheckOutRequest $request, Session $session): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->recorder->checkOut(
            $user,
            $session,
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('status', __('attendance.checked_out'));
    }

    /**
     * The participant's own log as a CSV. Same scope as the screen: their rows
     * and nobody else's (BR-22).
     */
    public function export(Request $request): \Illuminate\Http\Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Attendance::class);

        $cohort = $this->activeCohort($user);
        $rows = $cohort === null ? collect() : $this->log($user, (string) $cohort->getKey());

        $lines = ["\u{FEFF}".$this->csvRow([
            __('attendance.export.session'),
            __('attendance.export.status'),
        ])];

        foreach ($rows as $row) {
            $lines[] = $this->csvRow([
                (string) ($row->session?->getAttribute('title') ?? ''),
                (string) ($row->getAttribute('status')?->value ?? ''),
            ]);
        }

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="athar-my-attendance.csv"',
        ]);
    }

    /**
     * This participant's own rows, and only their own. Changing an id in the
     * URL is not possible here because no id is read from the URL at all.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Attendance>
     */
    private function logQuery(User $user, string $cohortId)
    {
        return Attendance::query()
            ->with(['session.week'])
            ->where('user_id', $user->getKey())
            ->whereIn('session_id', Session::query()->where('cohort_id', $cohortId)->select('id'))
            ->orderByDesc('created_at');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Attendance>
     */
    private function log(User $user, string $cohortId)
    {
        return $this->logQuery($user, $cohortId)->get();
    }

    /**
     * The session the two buttons refer to: the one running now, or the next
     * one that has not finished. A cancelled session is never offered.
     */
    private function currentOrNextSession(string $cohortId, \DateTimeInterface $now): ?Session
    {
        /** @var Session|null $session */
        $session = Session::query()
            ->where('cohort_id', $cohortId)
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereDate('date', '>=', Clock::toRiyadh($now)->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->first();

        return $session;
    }
}
