<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\AttendanceStatus;
use App\Enums\JourneyStepStatus;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Cohort;
use App\Models\JourneyStep;
use App\Models\Session;
use App\Models\Submission;
use App\Models\User;
use App\Presenters\Participant\JourneyDetailPresenter;
use App\Presenters\Participant\JourneyProgressPresenter;
use App\Presenters\Participant\JourneyStepPresenter;
use App\Presenters\Support\Present;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Journey\JourneyEvaluator;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * My journey — the ten steps (PRD §9.7).
 *
 * The screen reads state, it never decides it. Which steps are complete is
 * evaluated from real data by App\Services\Journey\JourneyEvaluator; there is
 * no endpoint anywhere that lets a participant mark a step done, and this
 * controller offers none (BR-21).
 *
 * A week step expands into its own days and its own assignments (PRD §9.7.2).
 * Those lines describe the rows that exist — an attendance record, a hand-in —
 * and never re-judge the step around them: that answer stays the evaluator's
 * (Art. 6).
 *
 * @see BR-20, BR-21, BR-22 · PRD §9.7 · CONSTITUTION Art. 5, Art. 6
 */
final class JourneyController extends Controller
{
    use ResolvesActiveCohort;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'journey';

    public function __construct(
        private readonly JourneyEvaluator $journey,
        private readonly AttendanceWindow $window,
    ) {}

    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $cohort = $this->activeCohort($user);

        if ($cohort === null) {
            return view('participant.journey', [
                'steps' => new Collection,
                'progress' => JourneyProgressPresenter::none(),
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $now = Clock::now();
        $overview = $this->journey->overview($user, $cohort);

        /** @var Collection<int, JourneyStep> $steps */
        $steps = $overview['steps'];
        $statuses = $overview['statuses'];

        $sessionDetails = $this->sessionDetails($user, $cohort);
        $assignmentDetails = $this->assignmentDetails($user, $cohort, $now);

        return view('participant.journey', [
            'steps' => $steps->map(function (JourneyStep $step) use (
                $statuses,
                $sessionDetails,
                $assignmentDetails,
            ): JourneyStepPresenter {
                $weekId = $this->relatedWeekId($step);

                $details = $weekId === null
                    ? new Collection
                    : ($sessionDetails->get($weekId, new Collection))
                        ->concat($assignmentDetails->get($weekId, new Collection));

                return JourneyStepPresenter::from(
                    $step,
                    $statuses[(string) $step->getKey()] ?? JourneyStepStatus::Locked,
                    $details,
                );
            }),
            'progress' => JourneyProgressPresenter::from($overview),
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::of($steps->isEmpty()),
        ]);
    }

    /**
     * The cohort's sessions grouped by week, each with this participant's own
     * attendance status and nobody else's (BR-22).
     *
     * @return Collection<string, Collection<int, JourneyDetailPresenter>>
     */
    private function sessionDetails(User $user, Cohort $cohort): Collection
    {
        $sessions = Session::query()
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $statuses = Attendance::query()
            ->where('user_id', $user->getKey())
            ->whereIn('session_id', $sessions->modelKeys())
            ->pluck('status', 'session_id');

        return $sessions
            ->filter(static fn (Session $session): bool => $session->getAttribute('week_id') !== null)
            ->groupBy(static fn (Session $session): string => (string) $session->getAttribute('week_id'))
            ->map(fn (Collection $group): Collection => $group->map(
                function (Session $session) use ($statuses): JourneyDetailPresenter {
                    $raw = $statuses->get((string) $session->getKey());
                    $status = $raw instanceof AttendanceStatus
                        ? $raw
                        : ($raw === null ? null : AttendanceStatus::tryFrom((string) $raw));

                    return JourneyDetailPresenter::forSession(
                        Present::text($session->getAttribute('topic'))
                            ?? (string) $session->getAttribute('title'),
                        $this->window->startsAt($session),
                        $status,
                    );
                },
            )->values());
    }

    /**
     * The cohort's published assignments grouped by week, each with whether
     * this participant has handed it in.
     *
     * @return Collection<string, Collection<int, JourneyDetailPresenter>>
     */
    private function assignmentDetails(User $user, Cohort $cohort, CarbonImmutable $now): Collection
    {
        $assignments = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', 'published')
            ->orderBy('due_at')
            ->get();

        $submitted = Submission::query()
            ->where('user_id', $user->getKey())
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->pluck('assignment_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->flip();

        return $assignments
            ->filter(static fn (Assignment $item): bool => $item->getAttribute('week_id') !== null)
            ->groupBy(static fn (Assignment $item): string => (string) $item->getAttribute('week_id'))
            ->map(static fn (Collection $group): Collection => $group->map(
                static fn (Assignment $item): JourneyDetailPresenter => JourneyDetailPresenter::forAssignment(
                    (string) $item->getAttribute('title'),
                    Present::toDateTime($item->getAttribute('due_at')),
                    $submitted->has((string) $item->getKey()),
                    $now,
                ),
            )->values());
    }

    /**
     * The week a step points at, when it points at one (PROJECT-CONTRACT §9).
     */
    private function relatedWeekId(JourneyStep $step): ?string
    {
        if ((string) $step->getAttribute('related_entity_type') !== JourneyEvaluator::ENTITY_WEEK) {
            return null;
        }

        $id = $step->getAttribute('related_entity_id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
