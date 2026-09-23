<?php

declare(strict_types=1);

namespace App\Http\Controllers\Coordinator;

use App\Enums\EnrollmentRole;
use App\Enums\SessionStatus;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Models\AttendanceExceptionRequest;
use App\Models\Enrollment;
use App\Models\Session;
use App\Presenters\Trainer\PendingExceptionRow;
use App\Presenters\Trainer\SessionRow;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The coordinator's own information dashboard (PR-5, batch 2).
 *
 * The coordinator role had exactly one screen before this — attendance
 * (D-105) — and, since D-109, full control of the schedule itself. Neither
 * gave a coordinator arriving for the day a single place to see what needs
 * them: a session with no join link or location yet (their own job to fill,
 * D-109), and the excuse requests still waiting on a decision (D-106).
 *
 * `sessionsNeedingAttention` reuses SessionRow's own `hasMeetingUrl`/
 * `isInPerson`/`hasLocation` flags rather than re-deriving the same
 * "is this session ready" logic a second time (Art. 6) — this screen only
 * filters the collection those flags already describe.
 *
 * @see D-105, D-106, D-109 · CONSTITUTION Art. 5, Art. 6, Art. 17
 */
final class DashboardController extends Controller
{
    use ReadsCohortScope;

    /** How many rows each queue shows before "view all" takes over. */
    private const QUEUE_LIMIT = 5;

    /** The Article 17 screen name. */
    private const SCREEN = 'coordinator-dashboard';

    public function __construct(private readonly AttendanceWindow $window) {}

    public function __invoke(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('coordinator.dashboard', [
                'contextLabel' => null,
                'participantCount' => 0,
                'nextSession' => null,
                'sessionsNeedingAttention' => new Collection,
                'pendingExceptions' => new Collection,
                'pendingExceptionsTotal' => 0,
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $cohortId = (string) $cohort->getKey();
        $today = Clock::toRiyadh(Clock::now())->toDateString();

        $upcoming = Session::query()
            ->with('trainer.profile')
            ->where('cohort_id', $cohortId)
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereDate('date', '>=', $today)
            ->orderBy('date')
            ->orderBy('start_time')
            ->limit(20)
            ->get()
            ->map(fn (Session $session): SessionRow => SessionRow::from($session, $this->window));

        $pendingExceptionsQuery = AttendanceExceptionRequest::query()
            ->pending()
            ->forCohort($cohort);

        return view('coordinator.dashboard', [
            'contextLabel' => $cohort->getAttribute('name'),
            'participantCount' => Enrollment::query()
                ->where('cohort_id', $cohortId)
                ->where('role_in_cohort', EnrollmentRole::Participant->value)
                ->count(),
            'nextSession' => $upcoming->first(),
            // D-109 — a session only a coordinator (or an admin) can now
            // complete: no join link for an online one, no place for an
            // in-person one.
            'sessionsNeedingAttention' => $upcoming
                ->filter(static fn (SessionRow $row): bool => $row->isInPerson ? ! $row->hasLocation : ! $row->hasMeetingUrl)
                ->take(self::QUEUE_LIMIT)
                ->values(),
            'pendingExceptions' => (clone $pendingExceptionsQuery)
                ->with(['user.profile', 'attendance.session'])
                ->orderBy('created_at')
                ->limit(self::QUEUE_LIMIT)
                ->get()
                ->map(static fn (AttendanceExceptionRequest $item): PendingExceptionRow => PendingExceptionRow::from($item)),
            'pendingExceptionsTotal' => (clone $pendingExceptionsQuery)->count(),
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::NORMAL,
        ]);
    }
}
