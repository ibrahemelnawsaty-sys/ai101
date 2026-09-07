<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\AttendanceStatus;
use App\Enums\SessionType;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Resource;
use App\Models\Session;
use App\Models\User;
use App\Models\Week;
use App\Presenters\Participant\CalendarDayPresenter;
use App\Presenters\Participant\CalendarPresenter;
use App\Presenters\Participant\ResourcePresenter;
use App\Presenters\Participant\SelectedSessionPresenter;
use App\Presenters\Participant\SessionPresenter;
use App\Presenters\Participant\WeekPresenter;
use App\Presenters\Support\Options;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Time\Clock;
use App\Support\AttendanceCounting;
use App\Support\ScreenState;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * The programme schedule, and the calendar files it can be exported to.
 *
 * Every session shown belongs to a cohort the account may reach; the query is
 * scoped before it is ordered, never filtered afterwards (BR-22, BR-23). The
 * meeting link is not part of any of these responses — not the page, not the
 * calendar file — because it is only revealed inside its own window (BR-24).
 *
 * Times in the calendar file are written in UTC with a trailing Z, which is the
 * only unambiguous form; the screen shows Asia/Riyadh (Art. 11).
 *
 * @see BR-07, BR-22, BR-23, BR-24 · PRD §9.8 · CONSTITUTION Art. 11, Art. 22
 */
final class ScheduleController extends Controller
{
    use ResolvesActiveCohort;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'schedule';

    public function __construct(
        private readonly AttendanceWindow $window,
        private readonly CertificateEligibility $eligibility,
    ) {}

    /**
     * The two views of PRD §9.8.1. Both are built here, on the server: the
     * accordion's per-week attendance tally, the calendar's today, and every
     * status badge are decided against Clock::now() and handed to the template
     * as presenters (Art. 5, Art. 11).
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Session::class);

        $cohort = $this->activeCohort($user);
        $now = Clock::now();
        $preferred = $request->query('view', $request->session()->get('schedule.view', 'accordion'));

        if ($cohort === null) {
            return view('participant.schedule', [
                'serverNow' => $now,
                'preferredView' => is_string($preferred) ? $preferred : 'accordion',
                'weeks' => new Collection,
                'calendar' => CalendarPresenter::from(new Collection, null, null, null, null),
                'selectedSession' => null,
                'weekOptions' => [],
                'typeOptions' => $this->typeOptions(),
                'attendanceOptions' => $this->attendanceOptions(),
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $weeks = $cohort->weeks()->orderBy('index')->get();
        $sessions = $this->sessions((string) $cohort->getKey());
        $nextSessionId = $this->nextSessionId($sessions, $now);
        $attended = $this->attendedSessionIds($user, $sessions);

        $presented = $sessions->map(
            fn (Session $session): SessionPresenter => SessionPresenter::from(
                $session,
                $this->window,
                $now,
                (string) $session->getKey() === $nextSessionId,
            )
        );

        $byWeek = $sessions->groupBy(
            static fn (Session $session): string => (string) ($session->getAttribute('week_id') ?? '')
        );

        $minimumRate = $this->eligibility->minAttendanceRate($cohort);

        $groups = $weeks->map(function (Week $week) use (
            $byWeek,
            $presented,
            $attended,
            $now,
            $minimumRate,
        ): WeekPresenter {
            $ids = ($byWeek->get((string) $week->getKey(), new Collection))
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $weekSessions = $presented->filter(
                static fn (SessionPresenter $item): bool => in_array($item->id, $ids, true)
            )->values();

            return WeekPresenter::from(
                $week,
                $now,
                $weekSessions,
                new Collection,
                $this->countAttended($ids, $attended),
                $minimumRate,
            );
        })->values();

        // PRD §7.3: `week_id` is deliberately left empty for a session that sits
        // outside the four weeks. Grouping by week alone dropped such a session
        // from both views, so it existed in the timetable and nowhere on screen.
        $looseIds = ($byWeek->get('', new Collection))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($looseIds !== []) {
            $groups->push(WeekPresenter::unscheduled(
                (string) __('schedule.unscheduled_group'),
                $presented->filter(
                    static fn (SessionPresenter $item): bool => in_array($item->id, $looseIds, true)
                )->values(),
                new Collection,
                $this->countAttended($looseIds, $attended),
                $minimumRate,
            ));
        }

        return view('participant.schedule', [
            'serverNow' => $now,
            'preferredView' => is_string($preferred) ? $preferred : 'accordion',
            'weeks' => $groups,
            'calendar' => $this->calendarPresenter($weeks, $sessions, $presented, $request, $now),
            'selectedSession' => $this->selectedSession($sessions, $request),
            'weekOptions' => $this->weekOptions($weeks),
            'typeOptions' => $this->typeOptions(),
            'attendanceOptions' => $this->attendanceOptions(),
            'errorState' => null,
            'screen' => self::SCREEN,
            // A timetable with no session at all is empty; one whose sessions
            // are all outside the weeks is not (Art. 17).
            'screenState' => ScreenState::of($sessions->isEmpty()),
        ]);
    }

    /**
     * The calendar strip and its seven day columns (PRD §9.8.1).
     *
     * @param  \Illuminate\Support\Collection<int, Week>  $weeks
     * @param  \Illuminate\Support\Collection<int, Session>  $sessions
     * @param  \Illuminate\Support\Collection<int, SessionPresenter>  $presented
     */
    private function calendarPresenter(
        Collection $weeks,
        Collection $sessions,
        Collection $presented,
        Request $request,
        CarbonImmutable $now,
    ): CalendarPresenter {
        if ($weeks->isEmpty()) {
            return CalendarPresenter::from(new Collection, null, null, null, null);
        }

        $requested = $request->query('week');
        $current = $weeks->first(
            static fn (Week $week): bool => (string) $week->getKey() === (is_string($requested) ? $requested : '')
        );

        if (! $current instanceof Week) {
            $today = Clock::toRiyadh($now)->toDateString();
            $current = $weeks->first(function (Week $week) use ($today): bool {
                $from = $week->getAttribute('start_date');
                $to = $week->getAttribute('end_date');

                return $from instanceof DateTimeInterface
                    && $to instanceof DateTimeInterface
                    && Clock::toRiyadh($from)->toDateString() <= $today
                    && Clock::toRiyadh($to)->toDateString() >= $today;
            }) ?? $weeks->first();
        }

        /** @var Week $current */
        $position = $weeks->search(static fn (Week $week): bool => $week->is($current));
        $previous = is_int($position) && $position > 0 ? $weeks->get($position - 1) : null;
        $next = is_int($position) ? $weeks->get($position + 1) : null;

        $from = $current->getAttribute('start_date');
        $to = $current->getAttribute('end_date');

        $days = new Collection;

        if ($from instanceof DateTimeInterface && $to instanceof DateTimeInterface) {
            $cursor = Clock::toRiyadh($from)->startOfDay();
            $last = Clock::toRiyadh($to)->startOfDay();

            while ($cursor->lessThanOrEqualTo($last)) {
                $date = $cursor->toDateString();

                $ids = $sessions
                    ->filter(static function (Session $session) use ($date): bool {
                        $on = $session->getAttribute('date');

                        return $on instanceof DateTimeInterface
                            && Clock::toRiyadh($on)->toDateString() === $date;
                    })
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all();

                $days->push(CalendarDayPresenter::from(
                    $cursor,
                    $now,
                    $presented->filter(
                        static fn (SessionPresenter $item): bool => in_array($item->id, $ids, true)
                    )->values(),
                ));

                $cursor = $cursor->addDay();
            }
        }

        return CalendarPresenter::from(
            $days,
            $from instanceof DateTimeInterface ? $from : null,
            $to instanceof DateTimeInterface ? $to : null,
            $previous instanceof Week ? (string) $previous->getKey() : null,
            $next instanceof Week ? (string) $next->getKey() : null,
        );
    }

    /**
     * The session the calendar panel is showing, if the URL names one this
     * cohort actually contains. An unknown id simply selects nothing, which
     * neither leaks its existence nor errors (BR-22).
     *
     * @param  \Illuminate\Support\Collection<int, Session>  $sessions
     */
    private function selectedSession(Collection $sessions, Request $request): ?SelectedSessionPresenter
    {
        $requested = $request->query('session');

        if (! is_string($requested) || $requested === '') {
            return null;
        }

        $session = $sessions->first(
            static fn (Session $item): bool => (string) $item->getKey() === $requested
        );

        if (! $session instanceof Session) {
            return null;
        }

        $resources = $session->relationLoaded('resources')
            ? $session->getRelation('resources')
            : new Collection;

        return SelectedSessionPresenter::from(
            $session,
            $this->window,
            (new Collection($resources))->map(
                static fn (Resource $resource): ResourcePresenter => ResourcePresenter::from($resource, Clock::now())
            )->values(),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Session>  $sessions
     */
    private function nextSessionId(Collection $sessions, CarbonImmutable $now): ?string
    {
        foreach ($sessions as $session) {
            if ($this->window->isCancelled($session)) {
                continue;
            }

            if ($this->window->endsAt($session)->greaterThanOrEqualTo($now)) {
                return (string) $session->getKey();
            }
        }

        return null;
    }

    /**
     * This participant's own attendance rows, and only their own (BR-22).
     *
     * @param  \Illuminate\Support\Collection<int, Session>  $sessions
     * @return array<string, true>
     */
    private function attendedSessionIds(User $user, Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $ids = Attendance::query()
            ->where('user_id', $user->getKey())
            ->whereIn('session_id', $sessions->pluck('id')->all())
            ->whereIn('status', AttendanceCounting::countedAsAttendedValues())
            ->pluck('session_id');

        $map = [];

        foreach ($ids as $id) {
            $map[(string) $id] = true;
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $sessionIds
     * @param  array<string, true>  $attended
     */
    private function countAttended(array $sessionIds, array $attended): int
    {
        $count = 0;

        foreach ($sessionIds as $id) {
            if (isset($attended[(string) $id])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Week>  $weeks
     * @return list<array{value: string, label: string}>
     */
    private function weekOptions(Collection $weeks): array
    {
        return Options::fromModels(
            $weeks,
            static fn (Model $week): string => (string) $week->getAttribute('title'),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return Options::fromEnum(SessionType::class);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function attendanceOptions(): array
    {
        return Options::fromEnum(AttendanceStatus::class);
    }

    /** A printable view of the same schedule; the browser makes the PDF. */
    public function print(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Session::class);

        $cohort = $this->activeCohort($user);

        return view('participant.schedule-print', [
            'serverNow' => Clock::now(),
            'cohort' => $cohort,
            'sessions' => $cohort === null ? collect() : $this->sessions((string) $cohort->getKey()),
        ]);
    }

    /** The whole cohort schedule as one calendar file. */
    public function calendar(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Session::class);

        $cohort = $this->activeCohort($user);
        $sessions = $cohort === null ? collect() : $this->sessions((string) $cohort->getKey());

        return $this->icsResponse($sessions->all(), 'athar-schedule.ics');
    }

    /**
     * One session as a calendar file. The policy is asked first, so changing
     * the id in the URL answers 403 rather than another cohort's session
     * (BR-22).
     */
    public function sessionCalendar(Session $session): Response
    {
        $this->authorize('view', $session);

        return $this->icsResponse([$session], 'athar-session.ics');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Session>
     */
    private function sessions(string $cohortId)
    {
        return Session::query()
            ->with(['week', 'trainer.profile', 'resources'])
            ->where('cohort_id', $cohortId)
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * @param  array<int, Session>  $sessions
     */
    private function icsResponse(array $sessions, string $filename): Response
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//athar//training//AR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($sessions as $session) {
            $start = Clock::composeRiyadh($session->getAttribute('date'), $session->getAttribute('start_time'));
            $end = Clock::composeRiyadh($session->getAttribute('date'), $session->getAttribute('end_time'));

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$session->getKey().'@'.config('athar.domain');
            $lines[] = 'DTSTAMP:'.Clock::now()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$start->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$end->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.$this->escape((string) $session->getAttribute('title'));
            $lines[] = 'DESCRIPTION:'.$this->escape((string) ($session->getAttribute('topic') ?? ''));
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** RFC 5545 escaping: commas, semicolons, backslashes and newlines. */
    private function escape(string $value): string
    {
        return str_replace(
            ["\\", ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n'],
            $value
        );
    }
}
