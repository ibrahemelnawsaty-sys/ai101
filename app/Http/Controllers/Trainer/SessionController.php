<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trainer;

use App\Enums\SessionDeliveryMode;
use App\Enums\SessionPlatform;
use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Events\SessionCancelled;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\CancelSessionRequest;
use App\Http\Requests\Trainer\StoreSessionRequest;
use App\Http\Requests\Trainer\UpdateSessionRecordingRequest;
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
use App\Services\Notifications\CohortNotices;
use App\Services\Notifications\InAppNotifier;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The cohort's schedule (PRD §9.8, §9.10).
 *
 * D-109 split who reads this screen from who writes it: the admin and the
 * coordinator create, edit, cancel and hold the meeting link; a trainer
 * reaches the same screen and the same rows, but `$canManage` (SessionPolicy's
 * own `create` answer) comes back false, so the view renders read-only cards
 * instead of the editor. The policy is still what a direct POST is checked
 * against — the flag only decides what the page offers.
 *
 * The meeting link is stored from this screen. It is never emitted to a
 * participant page before its window opens — that guard lives in the
 * participant controller, and this one only writes the value (BR-24).
 *
 * Cancelling demands a reason and is audited before the change lands (BR-27).
 *
 * @see BR-07, BR-23, BR-24, BR-27 · PRD §9.8, §9.10 · CONSTITUTION Art. 5, Art. 8, Art. 22
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
        private readonly CohortNotices $notices,
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
                'coordinatorOptions' => [],
                'typeOptions' => Options::fromEnum(SessionType::class),
                'statusOptions' => Options::fromEnum(SessionStatus::class),
                'deliveryModeOptions' => Options::fromEnum(SessionDeliveryMode::class),
                'platformOptions' => Options::fromEnum(SessionPlatform::class),
                'canManage' => false,
                'editing' => null,
                'cancelling' => null,
                'errorState' => null,
            ]);
        }

        $canManage = $this->canManage($request, (string) $cohort->getKey());

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
            'coordinatorOptions' => Options::fromModels(
                $cohort->coordinators()->with('profile')->get(),
                static fn (User $item): string => (string) (
                    $item->profile?->getAttribute('full_name_ar') ?? $item->getAttribute('email')
                ),
            ),
            'typeOptions' => Options::fromEnum(SessionType::class),
            'statusOptions' => Options::fromEnum(SessionStatus::class),
            'deliveryModeOptions' => Options::fromEnum(SessionDeliveryMode::class),
            'platformOptions' => Options::fromEnum(SessionPlatform::class),
            'canManage' => $canManage,
            'editing' => $canManage ? $this->editing($request) : null,
            'cancelling' => $canManage ? $this->cancelling($request) : null,
            'errorState' => null,
        ]);
    }

    /**
     * SessionPolicy::create's own answer, asked against a draft bound to this
     * cohort — the same shape StoreSessionRequest::authorize() checks against
     * (D-109). The view never decides this on its own.
     */
    private function canManage(Request $request, string $cohortId): bool
    {
        $draft = new Session;
        $draft->setAttribute('cohort_id', $cohortId);

        return (bool) $request->user()?->can('create', $draft);
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
        $before = $this->window->startsAt($session);

        $session->fill($request->columns())->save();

        // A new start is a postponement, and PRD §9.16.1 tells the whole cohort
        // at the change, on both channels (D-83). A new topic or link is not;
        // a cancelled session has already been announced as cancelled; and a
        // start that is now in the past is a correction to the record, not a
        // time anybody can still attend.
        $after = $this->window->startsAt($session);

        if (! $after->equalTo($before) && ! $session->isCancelled() && $after->greaterThan(Clock::now())) {
            $this->notices->sessionMoved(
                (string) $session->getAttribute('cohort_id'),
                (string) $session->getAttribute('title'),
                $after,
            );
        }

        return back()->with('status', __('trainer.sessions.updated'));
    }

    /**
     * D-107 — the one field a coordinator may set on a session they do not
     * otherwise manage. Extraction and the zoom.us check already happened in
     * the request; this only writes what came back.
     */
    public function updateRecording(UpdateSessionRecordingRequest $request, Session $session): RedirectResponse
    {
        $session->setAttribute('recording_url', $request->recordingUrl())->save();

        return back()->with('status', __('trainer.sessions.recording_saved'));
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
