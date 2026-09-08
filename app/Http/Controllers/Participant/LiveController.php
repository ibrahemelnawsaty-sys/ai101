<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\SessionStatus;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Participant\LiveSessionPresenter;
use App\Presenters\Participant\RecordingPresenter;
use App\Presenters\Participant\SessionPresenter;
use App\Presenters\Support\Options;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Live sessions (PRD §9.10).
 *
 * BR-24 in one sentence: the meeting URL never appears in a page. It is not
 * rendered, not placed in a data attribute and not sent to a participant who
 * merely looks at the schedule. `join` is the only way to it, and it re-checks
 * membership and the window on the server before answering with a redirect.
 *
 * The join window opens fifteen minutes before the start and closes at the end
 * (PRD §9.10); like every other window it is measured against Clock::now().
 *
 * @see BR-07, BR-22, BR-23, BR-24 · PRD §9.10 · CONSTITUTION Art. 5, Art. 11
 */
final class LiveController extends Controller
{
    use ResolvesActiveCohort;

    /** PRD §9.10 — the join button opens a quarter of an hour early. */
    public const JOIN_OPENS_BEFORE_START_MINUTES = 15;

    /** Article 19 — any list past fifty rows is paginated. */
    private const PER_PAGE = 20;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'live';

    public function __construct(private readonly AttendanceWindow $window) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Session::class);

        $cohort = $this->activeCohort($user);
        $now = Clock::now();

        if ($cohort === null) {
            return view('participant.live', [
                'serverNow' => $now,
                'featured' => LiveSessionPresenter::missing(),
                'upcoming' => new Collection,
                'recordings' => new Collection,
                'weekOptions' => [],
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $cohortId = (string) $cohort->getKey();
        $featured = $this->nextSession($cohortId, $now);

        $upcoming = $this->upcoming($cohortId, $now)->map(
            fn (Session $session): SessionPresenter => SessionPresenter::from($session, $this->window, $now),
        );

        $recordings = $this->recordings($cohortId)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Session $session): RecordingPresenter => RecordingPresenter::from($session, $this->window));

        return view('participant.live', [
            'serverNow' => $now,
            'featured' => $featured === null
                ? LiveSessionPresenter::missing()
                : LiveSessionPresenter::from($featured, $this->window, $now),
            'upcoming' => $upcoming,
            'recordings' => $recordings,
            'weekOptions' => Options::fromModels(
                $cohort->weeks()->orderBy('index')->get(),
                static fn (Model $week): string => (string) $week->getAttribute('title'),
            ),
            'errorState' => null,
            'screen' => self::SCREEN,
            // Nothing to feature, nothing coming and nothing recorded: there is
            // no lecture on this screen at all (Art. 17).
            'screenState' => ScreenState::of(
                $featured === null && $upcoming->isEmpty() && $recordings->getCollection()->isEmpty(),
            ),
        ]);
    }

    /**
     * Hand over the meeting URL, or refuse. Two checks, both on the server: the
     * policy answers "is this your cohort's session and is it not cancelled",
     * and the window answers "is it time". Neither is inferable from the page.
     */
    public function join(Session $session): RedirectResponse
    {
        $this->authorize('revealJoinLink', $session);

        $now = Clock::now();
        $start = $this->window->startsAt($session);
        $end = $this->window->endsAt($session);

        $opensAt = $start->subMinutes(self::JOIN_OPENS_BEFORE_START_MINUTES);

        if ($now->lessThan($opensAt) || $now->greaterThan($end)) {
            return back()->withErrors(['session' => __('live.errors.window_closed')]);
        }

        $url = $session->getAttribute('zoom_url');

        if (! is_string($url) || $url === '') {
            return back()->withErrors(['session' => __('live.errors.no_link')]);
        }

        return redirect()->away($url);
    }

    /** The recording, once the session is over and a recording exists. */
    public function recording(Session $session): RedirectResponse
    {
        $this->authorize('viewRecording', $session);

        $url = $session->getAttribute('recording_url');

        if (! is_string($url) || $url === '') {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return redirect()->away($url);
    }

    private function nextSession(string $cohortId, \DateTimeInterface $now): ?Session
    {
        /** @var Session|null $session */
        $session = Session::query()
            ->with('trainer.profile')
            ->where('cohort_id', $cohortId)
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereDate('date', '>=', Clock::toRiyadh($now)->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->first();

        return $session;
    }

    /**
     * @return Collection<int, Session>
     */
    private function upcoming(string $cohortId, \DateTimeInterface $now)
    {
        return Session::query()
            ->with('trainer.profile')
            ->where('cohort_id', $cohortId)
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereDate('date', '>=', Clock::toRiyadh($now)->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->limit(10)
            ->get();
    }

    /**
     * Recordings are paginated: the list grows with every finished session and
     * Article 19 caps an unpaginated list at fifty rows.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Session>
     */
    private function recordings(string $cohortId)
    {
        return Session::query()
            ->where('cohort_id', $cohortId)
            ->where('status', SessionStatus::Completed->value)
            ->whereNotNull('recording_url')
            ->orderByDesc('date');
    }
}
