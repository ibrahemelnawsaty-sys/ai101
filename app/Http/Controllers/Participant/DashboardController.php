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
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
    /**
     * The session key that marks the one render which celebrates a first
     * password (D-63). Written by FirstPasswordController, removed here only
     * after a successful render (D-75).
     */
    public const WELCOME_KEY = 'athar.welcome';

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
     * Build one dashboard card, or record that it could not be built.
     *
     * The failure is logged WITHOUT its message: an exception from a query can
     * carry bound values — addresses, names — and the dashboard is the one
     * screen every participant opens. The class and the line are what a
     * maintainer needs to find it; the trainee's data is not.
     *
     * @template T
     *
     * @param  list<string>  $failed  appended to when the card fails
     * @param  callable(): T  $build
     * @param  T  $fallback  the card's own empty form, so the template receives a
     *                       value of the right shape even though it shows the
     *                       error branch first
     * @return T
     */
    private function block(string $key, array &$failed, User $user, callable $build, mixed $fallback): mixed
    {
        try {
            return $build();
        } catch (\Throwable $failure) {
            Log::error('dashboard.block_failed', [
                'block' => $key,
                'user_id' => $user->getKey(),
                'exception' => $failure::class,
                'at' => $failure->getFile().':'.$failure->getLine(),
            ]);

            $failed[] = $key;

            return $fallback;
        }
    }

    /**
     * Whether this render is the one that celebrates. It reads; it never spends.
     *
     * A session value, not a query parameter and not a column: a parameter can
     * be typed by anybody, and a column would have to be reset for the next
     * cohort. It is NOT a flash any more. A flash is aged out when the request
     * that sees it ends — even a request that failed — so the first dashboard
     * render that threw spent the once-ever welcome on an error page, whatever
     * line the read sat on (D-75). respond() removes it only after the page has
     * rendered.
     */
    private function wantsWelcome(Request $request): bool
    {
        return (bool) $request->session()->get(self::WELCOME_KEY, false);
    }

    /**
     * Render now, then spend the welcome — in that order. `new Response($view)`
     * renders inside its constructor and keeps the View as its original, so a
     * render that throws leaves the key for the next visit, and tests can still
     * read the view's data.
     */
    private function respond(Request $request, bool $celebrate, View $view): Response
    {
        $response = new Response($view);

        if ($celebrate) {
            $request->session()->forget(self::WELCOME_KEY);
        }

        return $response;
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

    public function __invoke(Request $request): View|Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // PRD §8 gives /dashboard to every signed-in account and calls it the
        // dashboard home; §9.18 puts the six statistic cards on the
        // administrator's dashboard home. So an administrator opening
        // /dashboard is served that console home here rather than bounced to
        // another URL; /admin still serves the same screen directly.
        $shell = $this->roles->shellRole($user);

        // Those roles never celebrate; the key must not linger for them.
        if ($shell === 'admin') {
            $request->session()->forget(self::WELCOME_KEY);

            return app(AdminDashboardController::class)();
        }

        if ($shell === 'trainer') {
            $request->session()->forget(self::WELCOME_KEY);

            return redirect()->route('trainer.submissions');
        }

        $cohort = $this->activeCohort($user);
        $now = Clock::now();

        $user->loadMissing('profile');

        $celebrate = $this->wantsWelcome($request);

        if ($cohort === null) {
            return $this->respond($request, $celebrate, view('participant.dashboard', [
                'celebrate' => $celebrate,
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
            ]));
        }

        // Every card is built behind its own guard, and a card that throws is
        // named in $failed so the template shows ITS error state and leaves the
        // other eight standing. The template had nine such error branches and
        // the controller passed `'failedBlocks' => []` as a literal, so every
        // one was dead code: a single presenter throwing took the trainee's
        // whole first screen to a 500 (D-66).
        $failed = [];

        // BR-21 — completion is JourneyEvaluator's answer, read once and used by
        // both the greeting and the progress card. These two ran BEFORE the view
        // array and could throw the page down on their own.
        $overview = $this->block('journey', $failed, $user,
            fn (): array => $this->journey->overview($user, $cohort),
            ['steps' => new Collection, 'statuses' => [], 'completed' => 0, 'total' => 0, 'percent' => 0.0, 'current' => null]);

        $session = $this->block('nextSession', $failed, $user,
            fn (): ?Session => $this->nextSession($cohort->getKey(), $now),
            null);

        $welcome = $this->block('welcome', $failed, $user,
            static fn (): WelcomePresenter => WelcomePresenter::from(
                $user, $now, $overview['completed'], $overview['total'], $overview['percent'],
            ),
            // Null, not a second WelcomePresenter::from(): a fallback is
            // evaluated OUTSIDE the guard, so a throwing presenter here took
            // the whole page down anyway. The template renders the error branch
            // for a failed card before it ever reads the value.
            null);

        $nextSession = $this->block('nextSession', $failed, $user,
            fn (): NextSessionPresenter => $session === null
                ? NextSessionPresenter::missing()
                : NextSessionPresenter::from($session, $this->window, $now),
            NextSessionPresenter::missing());

        $attendance = $this->block('attendance', $failed, $user,
            fn (): AttendanceRatePresenter => AttendanceRatePresenter::from($user, $cohort, $this->eligibility, $now),
            AttendanceRatePresenter::none());

        $journey = $this->block('journey', $failed, $user,
            static fn (): JourneyProgressPresenter => JourneyProgressPresenter::from($overview),
            JourneyProgressPresenter::none());

        $gradeSummary = $this->block('grades', $failed, $user,
            fn (): GradeSummaryPresenter => GradeSummaryPresenter::from(
                $user, $cohort, $this->scores, $this->evaluations($user, $cohort),
            ),
            GradeSummaryPresenter::none());

        $dueAssignments = $this->block('dueAssignments', $failed, $user,
            fn (): Collection => $this->dueAssignments($user, (string) $cohort->getKey(), $now)
                ->map(static fn (Assignment $item): DueAssignmentPresenter => DueAssignmentPresenter::from($item, $now)),
            new Collection);

        $latestGrades = $this->block('latestGrades', $failed, $user,
            fn (): Collection => $this->latestGrades($user)
                ->map(static fn (Evaluation $item): LatestGradePresenter => LatestGradePresenter::from($item)),
            new Collection);

        $newResources = $this->block('resources', $failed, $user,
            fn (): Collection => $this->newResources((string) $cohort->getKey())
                ->map(static fn (Resource $item): ResourcePresenter => ResourcePresenter::from($item, $now)),
            new Collection);

        $announcements = $this->block('announcements', $failed, $user,
            fn (): Collection => $this->announcements($user)
                ->map(static fn (Notification $item): AnnouncementPresenter => AnnouncementPresenter::from($item)),
            new Collection);

        return $this->respond($request, $celebrate, view('participant.dashboard', [
            // Removed only after the page rendered (respond()). The old
            // "pulled after everything that can throw" protected nothing: a
            // flash is aged out at the end of a failed request anyway (D-75).
            'celebrate' => $celebrate,
            'celebrateName' => $this->greetingName($user),
            'failedBlocks' => array_values(array_unique($failed)),
            'cohortLabel' => $cohort->getAttribute('name'),
            'serverNow' => $now,
            'welcome' => $welcome,
            'nextSession' => $nextSession,
            'attendance' => $attendance,
            'journey' => $journey,
            'gradeSummary' => $gradeSummary,
            'dueAssignments' => $dueAssignments,
            'latestGrades' => $latestGrades,
            'newResources' => $newResources,
            'announcements' => $announcements,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::NORMAL,
        ]));
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
