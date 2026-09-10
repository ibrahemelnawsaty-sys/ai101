<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\SessionStatus;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Evaluation;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Resource;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Participant\AnnouncementPresenter;
use App\Presenters\Participant\AttendanceRatePresenter;
use App\Presenters\Participant\DueAssignmentPresenter;
use App\Presenters\Participant\GradeSummaryPresenter;
use App\Presenters\Participant\JourneyProgressPresenter;
use App\Presenters\Participant\LatestGradePresenter;
use App\Presenters\Participant\NextSessionPresenter;
use App\Presenters\Participant\ResourcePresenter;
use App\Presenters\Participant\WelcomePresenter;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Services\Journey\JourneyEvaluator;
use App\Services\Permissions\RoleResolver;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The dashboard home (PRD §9.5.3).
 *
 * Whoever signs in lands here; an administrator and a trainer are forwarded to
 * the screen their role starts on, and everybody else gets the nine cards.
 *
 * Each card is fetched independently and eager-loaded, so one slow or failing
 * card never takes the page down with it: a card that could not be built is
 * named in `$failedBlocks` and renders its own error state (Art. 17). Nothing
 * on this page decides a permission — every query is already scoped to the
 * signed-in account and its cohort (BR-22).
 *
 * Every card is handed to the view as a presenter, never as an array: the
 * template reads `$attendance->rateVariant` and prints it, and the variant was
 * decided here from CertificateEligibility's answer (Art. 5, Art. 6).
 *
 * @see BR-07, BR-11, BR-22, BR-26, BR-31 · PRD §9.5.3 · CONSTITUTION Art. 5, Art. 17, Art. 22
 */
final class DashboardController extends Controller
{
    use ResolvesActiveCohort;

    /** How many items each list card shows. */
    private const CARD_LIMIT = 3;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'dashboard';

    public function __construct(
        private readonly RoleResolver $roles,
        private readonly ScoreCalculator $scores,
        private readonly CertificateEligibility $eligibility,
        private readonly JourneyEvaluator $journey,
        private readonly AttendanceWindow $window,
    ) {}

    /**
     * Whether this render is the one that celebrates.
     *
     * A session flash, not a query parameter and not a column. A parameter can
     * be typed by anybody, so `?welcome=1` would let a trainee replay the
     * celebration forever and let anyone fake it. A column would have to be
     * reset for the next cohort and would still be read on a refresh. A flash
     * is spent by the render that reads it and survives nothing — which is the
     * lifetime this belongs to.
     */
    private function takeWelcome(Request $request): bool
    {
        return (bool) $request->session()->pull('athar.welcome', false);
    }

    /**
     * The name to greet by, or an empty string.
     *
     * NOT `WelcomePresenter::firstName()`. That falls back to the e-mail
     * address when a profile carries no Arabic first name, which is right for a
     * dense dashboard line and wrong for a greeting: "welcome, <at-sign
     * address>" is a reachable sentence, and the celebration carries a
     * name-less title written for exactly this case
     * (`dashboard.welcome_first.title_plain`).
     */
    private function greetingName(User $user): string
    {
        $profile = $user->relationLoaded('profile') ? $user->getRelation('profile') : null;

        if (! $profile instanceof Profile) {
            return '';
        }

        return trim((string) $profile->getAttribute('first_name_ar'));
    }

    public function __invoke(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // PRD §8 gives /dashboard to every signed-in account and calls it the
        // dashboard home; §9.18 puts the six statistic cards on the
        // administrator's dashboard home. So an administrator opening
        // /dashboard is served that console home here rather than bounced to
        // another URL; /admin still serves the same screen directly.
        if ($this->roles->isAdmin($user)) {
            return app(AdminDashboardController::class)();
        }

        if (! $this->roles->hasRole($user, 'participant') && $this->roles->hasRole($user, 'trainer')) {
            return redirect()->route('trainer.submissions');
        }

        $cohort = $this->activeCohort($user);
        $now = Clock::now();

        $user->loadMissing('profile');

        if ($cohort === null) {
            return view('participant.dashboard', [
                'celebrate' => $this->takeWelcome($request),
                'celebrateName' => $this->greetingName($user),
                'failedBlocks' => [],
                'cohortLabel' => null,
                'serverNow' => $now,
                'welcome' => WelcomePresenter::from($user, $now, 0, 0, 0.0),
                'nextSession' => NextSessionPresenter::missing(),
                'attendance' => AttendanceRatePresenter::none(),
                'journey' => JourneyProgressPresenter::none(),
                'gradeSummary' => GradeSummaryPresenter::none(),
                'dueAssignments' => new Collection,
                'latestGrades' => new Collection,
                'newResources' => new Collection,
                'announcements' => new Collection,
                'screen' => self::SCREEN,
                // No cohort to belong to yet: the nine cards have nothing to
                // say, and the screen says so rather than showing nine blanks.
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        // BR-21 — completion is JourneyEvaluator's answer, read once and used by
        // both the greeting and the progress card.
        $overview = $this->journey->overview($user, $cohort);
        $session = $this->nextSession($cohort->getKey(), $now);

        return view('participant.dashboard', [
            // Pulled HERE, after the two calls above that can throw. Read at the
            // top of the method, one exception on the first render would spend
            // the flash on a page the trainee never saw — and the celebration
            // is a once-ever thing (D-63).
            'celebrate' => $this->takeWelcome($request),
            'celebrateName' => $this->greetingName($user),
            'failedBlocks' => [],
            'cohortLabel' => $cohort->getAttribute('name'),
            'serverNow' => $now,
            'welcome' => WelcomePresenter::from(
                $user,
                $now,
                $overview['completed'],
                $overview['total'],
                $overview['percent'],
            ),
            'nextSession' => $session === null
                ? NextSessionPresenter::missing()
                : NextSessionPresenter::from($session, $this->window, $now),
            'attendance' => AttendanceRatePresenter::from($user, $cohort, $this->eligibility, $now),
            'journey' => JourneyProgressPresenter::from($overview),
            'gradeSummary' => GradeSummaryPresenter::from(
                $user,
                $cohort,
                $this->scores,
                $this->evaluations($user, $cohort),
            ),
            'dueAssignments' => $this->dueAssignments($user, (string) $cohort->getKey(), $now)
                ->map(static fn (Assignment $item): DueAssignmentPresenter => DueAssignmentPresenter::from($item, $now)),
            'latestGrades' => $this->latestGrades($user)
                ->map(static fn (Evaluation $item): LatestGradePresenter => LatestGradePresenter::from($item)),
            'newResources' => $this->newResources((string) $cohort->getKey())
                ->map(static fn (Resource $item): ResourcePresenter => ResourcePresenter::from($item, $now)),
            'announcements' => $this->announcements($user)
                ->map(static fn (Notification $item): AnnouncementPresenter => AnnouncementPresenter::from($item)),
            'screen' => self::SCREEN,
            'screenState' => ScreenState::NORMAL,
        ]);
    }

    /**
     * The next session of this cohort that has not finished yet. The Zoom link
     * is never part of this query: it is revealed by its own guarded endpoint
     * inside its own window (BR-24).
     */
    private function nextSession(mixed $cohortId, \DateTimeInterface $now): ?Session
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
     * Published assignments of this cohort that are still open, with the
     * signed-in participant's own submissions eager-loaded — never anyone
     * else's (BR-22).
     *
     * @return Collection<int, Assignment>
     */
    private function dueAssignments(User $user, string $cohortId, CarbonImmutable $now)
    {
        return Assignment::query()
            ->with(['submissions' => static fn ($query) => $query->where('user_id', $user->getKey())])
            ->where('cohort_id', $cohortId)
            ->where('status', 'published')
            ->where('due_at', '>=', $now)
            ->orderBy('due_at')
            ->limit(self::CARD_LIMIT)
            ->get();
    }

    /**
     * This participant's marks in this cohort. The grade card needs them all —
     * not the three most recent - because the recorded-so-far denominator is a sum
     * over every recorded mark (BR-11).
     *
     * @return Collection<int, Evaluation>
     */
    private function evaluations(User $user, Cohort $cohort)
    {
        return Evaluation::query()
            ->forUser($user)
            ->forCohort($cohort)
            ->get();
    }

    /**
     * @return Collection<int, Evaluation>
     */
    private function latestGrades(User $user)
    {
        return Evaluation::query()
            ->with(['submission.assignment', 'projectSubmission.finalProject'])
            ->where('user_id', $user->getKey())
            ->orderByDesc('evaluated_at')
            ->limit(self::CARD_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, resource>
     */
    private function newResources(string $cohortId)
    {
        return Resource::query()
            ->where('cohort_id', $cohortId)
            ->orderByDesc('created_at')
            ->limit(self::CARD_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, Notification>
     */
    private function announcements(User $user)
    {
        return Notification::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->limit(self::CARD_LIMIT)
            ->get();
    }
}
