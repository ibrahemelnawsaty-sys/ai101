<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Events\SessionCancelled;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\CancelSessionRequest;
use App\Http\Requests\Trainer\StoreSessionRequest;
use App\Http\Requests\Trainer\UpdateSessionRequest;
use App\Models\Session;
use App\Models\User;
use App\Models\Week;
use App\Presenters\Support\Options;
use App\Presenters\Trainer\SessionForm;
use App\Presenters\Trainer\SessionRow;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\CohortAudience;
use App\Services\Notifications\InAppNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Managing the schedule of one's own cohorts (PRD §9.8, §9.10).
 *
 * The meeting link is stored from this screen. It is never emitted to a
 * participant page before its window opens — that guard lives in the
 * participant controller, and this one only writes the value (BR-24).
 *
 * Cancelling demands a reason and is audited before the change lands (BR-27).
 *
 * @see BR-07, BR-23, BR-24, BR-27 · PRD §9.8, §9.10 · CONSTITUTION Art. 8, Art. 22
 */
final class SessionController extends Controller
{
    use ReadsCohortScope;

    private const PER_PAGE = 50;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttendanceWindow $window,
        private readonly InAppNotifier $notifier,
        private readonly CohortAudience $audience,
    ) {}

    public function index(Request $request): View
    {
        $cohort = $this->scopedCohort($request);

        if ($cohort === null) {
            return view('trainer.sessions', [
                'contextLabel' => null,
                'sessions' => collect(),
                'weekOptions' => [],
                'trainerOptions' => [],
                'typeOptions' => Options::fromEnum(SessionType::class),
                'statusOptions' => Options::fromEnum(SessionStatus::class),
                'editing' => null,
                'cancelling' => null,
                'errorState' => null,
            ]);
        }

        $query = Session::query()
            ->with(['week', 'trainer.profile'])
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('date')
            ->orderBy('start_time');

        $week = $request->query('week');

        if (is_string($week) && $week !== '') {
            $query->where('week_id', $week);
        }

        $status = $request->query('status');

        if (is_string($status) && SessionStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $window = $this->window;

        return view('trainer.sessions', [
            'contextLabel' => $cohort->getAttribute('name'),
            'sessions' => $query->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(static fn (Session $session): SessionRow => SessionRow::from($session, $window)),
            'weekOptions' => Options::fromModels(
                $cohort->weeks()->orderBy('index')->get(),
                static fn (Week $item): string => (string) $item->getAttribute('title'),
            ),
            'trainerOptions' => Options::fromModels(
                $cohort->trainers()->with('profile')->get(),
                static fn (User $item): string => (string) (
                    $item->profile?->getAttribute('full_name_ar') ?? $item->getAttribute('email')
                ),
            ),
            'typeOptions' => Options::fromEnum(SessionType::class),
            'statusOptions' => Options::fromEnum(SessionStatus::class),
            'editing' => $this->editing($request),
            'cancelling' => $this->cancelling($request),
            'errorState' => null,
        ]);
    }

    /**
     * The editor panel, open on `?edit=new` or `?edit={id}`.
     *
     * A session from another cohort never reaches the panel: the query is bound
     * to the cohort `cohort.scope` resolved, and the update endpoint re-checks
     * the policy on top of that (BR-23, art. 5).
     */
    private function editing(Request $request): ?SessionForm
    {
        $edit = $request->query('edit');
        $cohortId = $this->scopedCohortId($request);

        if (! is_string($edit) || $edit === '' || $cohortId === null) {
            return null;
        }

        if ($edit === 'new') {
            $week = $request->query('week');

            return SessionForm::blank(is_string($week) && $week !== '' ? $week : null);
        }

        /** @var Session|null $session */
        $session = Session::query()->where('cohort_id', $cohortId)->find($edit);

        return $session === null ? null : SessionForm::from($session);
    }

    /** The cancellation panel, open on `?cancel={id}`. */
    private function cancelling(Request $request): ?SessionRow
    {
        $cancel = $request->query('cancel');
        $cohortId = $this->scopedCohortId($request);

        if (! is_string($cancel) || $cancel === '' || $cohortId === null) {
            return null;
        }

        /** @var Session|null $session */
        $session = Session::query()
            ->with(['week', 'trainer.profile'])
            ->where('cohort_id', $cohortId)
            ->find($cancel);

        return $session === null ? null : SessionRow::from($session, $this->window);
    }

    public function store(StoreSessionRequest $request): RedirectResponse
    {
        Session::query()->create($request->columns());

        return back()->with('status', __('trainer.sessions.created'));
    }

    public function update(UpdateSessionRequest $request, Session $session): RedirectResponse
    {
        $session->fill($request->columns())->save();

        return back()->with('status', __('trainer.sessions.updated'));
    }

    /**
     * Cancelling keeps the row and its history: the session is marked
     * cancelled with its reason, never deleted, and attendance for it is
     * refused from that moment on (PROJECT-CONTRACT §6).
     */
    public function cancel(CancelSessionRequest $request, Session $session): RedirectResponse
    {
        $before = $this->audit->snapshot($session, ['status', 'cancellation_reason']);
        $wasCancelled = $session->getAttribute('status') === SessionStatus::Cancelled;

        $session->setAttribute('status', SessionStatus::Cancelled->value);
        $session->setAttribute('cancellation_reason', $request->reason());

        $this->audit->log(
            action: 'session.cancelled',
            entity: $session,
            before: $before,
            after: $this->audit->snapshot($session, ['status', 'cancellation_reason']),
        );

        $session->save();

        // The screen promised "a notification and an email go out on save",
        // and nothing went out (D-77). Only the move into cancelled announces.
        if (! $wasCancelled) {
            $cohortId = (string) $session->getAttribute('cohort_id');
            $title = (string) $session->getAttribute('title');
            $values = ['session' => $title, 'reason' => $request->reason()];

            $this->notifier->notify(
                $this->audience->participants($cohortId)->map(static fn (User $user): string => (string) $user->getKey()),
                'session_cancelled',
                (string) __('notifications.types.session_cancelled.title', $values),
                (string) __('notifications.types.session_cancelled.body', $values),
                route('schedule'),
            );

            SessionCancelled::dispatch($cohortId, $title, $request->reason());
        }

        return back()->with('status', __('trainer.sessions.cancelled'));
    }
}
