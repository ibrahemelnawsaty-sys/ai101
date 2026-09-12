<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Concerns\ExportsCsv;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\BulkAttendanceRequest;
use App\Http\Requests\Trainer\UpdateAttendanceRequest;
use App\Models\Attendance;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Support\Options;
use App\Presenters\Trainer\AtRiskPerson;
use App\Presenters\Trainer\AttendanceEditForm;
use App\Presenters\Trainer\AttendanceMatrix;
use App\Presenters\Trainer\AttendanceRoster;
use App\Presenters\Trainer\MatrixRow;
use App\Presenters\Trainer\RosterEntry;
use App\Presenters\Trainer\SessionRow;
use App\Services\Attendance\AttendanceRecorder;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Certificates\CertificateEligibility;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Attendance as the trainer manages it (PRD §9.9.7).
 *
 * Every manual change goes through AttendanceRecorder::manualOverride, which
 * demands a written reason of at least ten characters and writes the previous
 * values to audit_logs before the new ones land (BR-10, BR-27). This controller
 * never writes an attendance row itself.
 *
 * The roster is scoped to the cohort `cohort.scope` resolved; a session from
 * another cohort is refused by the policy with 403 (BR-23).
 *
 * @see BR-07, BR-08, BR-09, BR-10, BR-23, BR-27 · PRD §9.9.7 · CONSTITUTION Art. 8, Art. 22
 */
final class AttendanceController extends Controller
{
    use ExportsCsv;
    use ReadsCohortScope;

    /** How many people one matrix page shows (art. 19). */
    private const PER_PAGE = 25;

    /* AT_RISK_LIMIT was removed. Its docblock said it bounded the at-risk
       sweep, but it was applied to participants() — the query that also builds
       the marking roster — so it capped the roster as a side effect and hid
       participant 301 from the trainer entirely. A cap on a derived list must
       be applied where that list is derived, never on the shared query. */

    /** The Article 17 screen name. */
    private const SCREEN = 'trainer-attendance';

    public function __construct(
        private readonly AttendanceRecorder $recorder,
        private readonly AttendanceWindow $window,
        private readonly CertificateEligibility $eligibility,
    ) {}

    public function index(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('trainer.attendance', [
                'contextLabel' => null,
                'roster' => AttendanceRoster::none(),
                'matrix' => AttendanceMatrix::of(collect(), $this->emptyPage($request)),
                'sessionOptions' => [],
                'statusOptions' => Options::fromEnum(AttendanceStatus::class),
                'atRisk' => collect(),
                'editing' => null,
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $sessions = Session::query()
            ->with('trainer.profile')
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $session = $this->selectedSession($request, $sessions);
        $participants = $this->participants($cohort);

        // One rate query for the whole cohort, from the service that owns the
        // counting rule — never a loop of per-person calls (art. 6, art. 19).
        $rates = $this->eligibility->attendanceRates(
            array_map(static fn (mixed $id): string => (string) $id, $participants->modelKeys()),
            $cohort,
        );

        $records = $session === null ? collect() : $this->records($session);

        return view('trainer.attendance', [
            'contextLabel' => $cohort->getAttribute('name'),
            'roster' => $session === null
                ? AttendanceRoster::none()
                : AttendanceRoster::of($session, $participants, $records, $this->window),
            'matrix' => $this->matrix($request, $cohort, $sessions, $participants, $rates),
            'sessionOptions' => Options::fromModels(
                $sessions,
                static fn (Session $item): string => (string) (
                    $item->getAttribute('topic') ?? $item->getAttribute('title')
                ),
            ),
            'statusOptions' => Options::fromEnum(AttendanceStatus::class),
            'atRisk' => $this->atRisk($cohort, $participants, $rates),
            'editing' => $this->editing($request, $session, $records, $participants),
            'rosterPollSeconds' => max(0, (int) config('athar.attendance.roster_poll_seconds')),
            'errorState' => null,
            'screen' => self::SCREEN,
            // No session has been held yet, so there is no attendance board to
            // show — which is not the same as a board that failed to load.
            'screenState' => ScreenState::of($sessions->isEmpty()),
        ]);
    }

    /**
     * Active participants of the scoped cohort.
     *
     * @return EloquentCollection<int, User>
     */
    private function participants(Cohort $cohort): EloquentCollection
    {
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
            // No limit. This used to stop at 300 and say nothing: participant
            // 301 was absent from the roster, could not be marked, and appeared
            // to the trainer as though they were not enrolled. That is lost
            // attendance data, not a display cap.
            //
            // OWED: Article 20 makes pagination mandatory above 50 rows, and a
            // full cohort already exceeds that. It is not done here because the
            // roster is also the bulk-marking form — paginating it changes what
            // a "mark all" submission covers, and that behaviour cannot be
            // changed blind. Tracked as D-44.
            ->get();
    }

    /**
     * The manual-edit panel, open on `?edit={participant}`.
     *
     * The endpoint it posts to addresses the ATTENDANCE row, so the panel only
     * opens for somebody who has one; a participant with no record yet is
     * corrected by recording attendance, not by editing nothing.
     *
     * @param  Collection<string, Attendance>  $records
     * @param  Collection<int, User>  $participants
     */
    private function editing(
        Request $request,
        ?Session $session,
        Collection $records,
        Collection $participants,
    ): ?AttendanceEditForm {
        $id = $request->query('edit');

        if ($session === null || ! is_string($id) || $id === '') {
            return null;
        }

        $record = $records->get($id);
        $participant = $participants->first(
            static fn (User $user): bool => (string) $user->getKey() === $id,
        );

        return $record instanceof Attendance && $participant instanceof User
            ? AttendanceEditForm::from($record, $session, $participant)
            : null;
    }

    /**
     * Participants under the cohort's minimum attendance rate (BR-26).
     *
     * @param  Collection<int, User>  $participants
     * @param  array<string, float>  $rates
     * @return Collection<int, AtRiskPerson>
     */
    private function atRisk(Cohort $cohort, Collection $participants, array $rates): Collection
    {
        $minimum = $this->eligibility->minAttendanceRate($cohort);

        return $participants
            ->filter(static fn (User $user): bool => ($rates[(string) $user->getKey()] ?? 0.0) < $minimum)
            ->map(fn (User $user): AtRiskPerson => AtRiskPerson::of(
                $user,
                $rates[(string) $user->getKey()] ?? 0.0,
                $minimum,
                null,
                [],
            ))
            ->values();
    }

    /**
     * The live roster of one session (PRD §9.9.7): the four cells of each row
     * that can change, rendered by the same partials the page uses, and the
     * present count.
     *
     * It returned raw status values nobody read — its client had been removed
     * in D-55 — and it asked manageAttendance, a WRITE ability, for a read, so
     * a preview was refused. Now a read ability, throttled, and the markup is
     * the server's: the browser never re-implements a label or a variant.
     */
    public function poll(Session $session): JsonResponse
    {
        $this->authorize('viewAttendance', $session);

        /** @var Cohort $cohort */
        $cohort = Cohort::query()->findOrFail($session->getAttribute('cohort_id'));
        $roster = AttendanceRoster::of($session, $this->participants($cohort), $this->records($session), $this->window);

        $rows = [];

        /** @var iterable<int, RosterEntry> $entries */
        $entries = $roster->get('entries', []);

        foreach ($entries as $entry) {
            $rows[(string) $entry->get('participantId')] = [
                'in' => (string) $entry->get('checkedInLabel'),
                'out' => (string) $entry->get('checkedOutLabel'),
                'status' => view('trainer.partials.roster-status', ['entry' => $entry])->render(),
                'edit' => view('trainer.partials.roster-edit', ['entry' => $entry])->render(),
            ];
        }

        return new JsonResponse([
            'isLive' => (bool) $roster->get('isLive'),
            'present' => (int) $roster->get('presentCount'),
            'rows' => $rows,
        ]);
    }

    /** BR-10 — one manual correction, with its reason, audited. */
    public function update(UpdateAttendanceRequest $request, Attendance $attendance): RedirectResponse
    {
        /** @var User $editor */
        $editor = $request->user();

        $this->recorder->manualOverride(
            $editor,
            $attendance,
            $request->status(),
            $request->reason(),
        );

        return back()->with('status', __('attendance.manual_saved'));
    }

    /**
     * BR-10 for several people at once. Each row still goes through the same
     * service, with the same reason, so a bulk action is a series of audited
     * corrections rather than an unlogged sweep.
     */
    public function bulk(BulkAttendanceRequest $request, Session $session): RedirectResponse
    {
        /** @var User $editor */
        $editor = $request->user();
        $status = $request->status();
        $reason = $request->reason();

        $requested = $request->userIds();

        $records = Attendance::query()
            ->where('session_id', $session->getKey())
            ->whereIn('user_id', $requested)
            ->get();

        foreach ($records as $record) {
            $this->recorder->manualOverride($editor, $record, $status, $reason);
        }

        // The ones with no row at all — and they are the POINT of marking by
        // hand. A row exists only once somebody has checked in, so anyone who
        // never opened the link has none, and this loop used to skip them in
        // silence and still flash success. A trainer ticked twelve no-shows,
        // read the `attendance.bulk_saved` confirmation, and nothing was
        // written (D-65).
        //
        // Scoped, not trusted: the ids come from a form, so each is re-read as a
        // participant actually enrolled in THIS session's cohort before anything
        // is written for them (art. 5).
        $missing = array_values(array_diff($requested, $records->pluck('user_id')->all()));

        if ($missing !== []) {
            $participants = User::query()
                ->whereKey($missing)
                ->whereIn(
                    'id',
                    Enrollment::query()
                        ->where('cohort_id', $session->getAttribute('cohort_id'))
                        ->where('role_in_cohort', EnrollmentRole::Participant->value)
                        // The roster this form came from lists ACTIVE
                        // participants; a crafted id for a pending or withdrawn
                        // one is not on it and is not written for (D-72).
                        ->where('status', EnrollmentStatus::Active->value)
                        ->select('user_id'),
                )
                ->get();

            foreach ($participants as $participant) {
                $this->recorder->markWithoutRecord($editor, $session, $participant, $status, $reason);
            }
        }

        return back()->with('status', count($requested) === 1
            ? __('attendance.manual_saved')
            : __('attendance.bulk_saved'));
    }

    /** The cohort's attendance matrix as a CSV. */
    public function export(Request $request): Response
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->authorize('export', $cohort);

        $rows = Attendance::query()
            ->with(['user.profile', 'session'])
            ->whereIn(
                'session_id',
                Session::query()->where('cohort_id', $cohort->getKey())->select('id'),
            )
            ->orderBy('created_at')
            ->get();

        $lines = ["\u{FEFF}".$this->csvRow([
            __('attendance.export.participant'),
            __('attendance.export.session'),
            __('attendance.export.status'),
        ])];

        foreach ($rows as $row) {
            $lines[] = $this->csvRow([
                (string) ($row->user?->profile?->getAttribute('full_name_ar') ?? ''),
                (string) ($row->session?->getAttribute('title') ?? ''),
                (string) ($row->getAttribute('status')->value ?? ''),
            ]);
        }

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="athar-attendance.csv"',
        ]);
    }

    /**
     * @param  Collection<int, Session>  $sessions
     */
    private function selectedSession(Request $request, Collection $sessions): ?Session
    {
        $requested = $request->query('session');

        if (is_string($requested) && $requested !== '') {
            $match = $sessions->first(
                static fn (Session $session): bool => (string) $session->getKey() === $requested,
            );

            if ($match instanceof Session) {
                return $match;
            }
        }

        $first = $sessions->first();

        return $first instanceof Session ? $first : null;
    }

    /**
     * Every attendance row of one session, keyed by the participant it belongs
     * to so the roster can pair them up without a lookup query per person.
     *
     * @return Collection<string, Attendance>
     */
    private function records(Session $session): Collection
    {
        return Attendance::query()
            ->where('session_id', $session->getKey())
            ->get()
            ->keyBy(static fn (Attendance $record): string => (string) $record->getAttribute('user_id'));
    }

    /**
     * The cohort matrix: sessions across, people down, one page at a time.
     *
     * Statuses are fetched in a single query for the people on this page only,
     * and every row is built against the same column order (art. 19).
     *
     * @param  EloquentCollection<int, Session>  $sessions
     * @param  EloquentCollection<int, User>  $participants
     * @param  array<string, float>  $rates
     */
    private function matrix(
        Request $request,
        Cohort $cohort,
        EloquentCollection $sessions,
        EloquentCollection $participants,
        array $rates,
    ): AttendanceMatrix {
        $window = $this->window;

        $columns = $sessions
            ->map(static fn (Session $session): SessionRow => SessionRow::from($session, $window))
            ->values();

        $sessionIds = array_map(
            static fn (mixed $id): string => (string) $id,
            $sessions->modelKeys(),
        );

        $page = LengthAwarePaginator::resolveCurrentPage('roster');
        $slice = $participants->forPage($page, self::PER_PAGE)->values();

        $statuses = $this->statusesFor($sessionIds, $slice);
        $minimum = $this->eligibility->minAttendanceRate($cohort);

        $rows = $slice
            ->map(static fn (User $user): MatrixRow => MatrixRow::of(
                $user,
                $sessionIds,
                $statuses[(string) $user->getKey()] ?? [],
                $rates[(string) $user->getKey()] ?? 0.0,
                $minimum,
            ))
            ->values();

        return AttendanceMatrix::of($columns, new LengthAwarePaginator(
            $rows,
            $participants->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'roster'],
        ));
    }

    /**
     * @param  array<int, string>  $sessionIds
     * @param  EloquentCollection<int, User>  $participants
     * @return array<string, array<string, AttendanceStatus>>
     */
    private function statusesFor(array $sessionIds, EloquentCollection $participants): array
    {
        if ($sessionIds === [] || $participants->isEmpty()) {
            return [];
        }

        $map = [];

        $rows = Attendance::query()
            ->whereIn('session_id', $sessionIds)
            ->whereIn('user_id', $participants->modelKeys())
            ->get(['user_id', 'session_id', 'status']);

        foreach ($rows as $row) {
            $status = $row->getAttribute('status');

            if ($status instanceof AttendanceStatus) {
                $map[(string) $row->getAttribute('user_id')][(string) $row->getAttribute('session_id')] = $status;
            }
        }

        return $map;
    }

    /**
     * An empty matrix page, for a trainer with no cohort assigned yet.
     *
     * @return LengthAwarePaginator<int, MatrixRow>
     */
    private function emptyPage(Request $request): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            collect(),
            0,
            self::PER_PAGE,
            1,
            ['path' => $request->url(), 'pageName' => 'roster'],
        );
    }
}
