<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Models\Attendance;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Time\Clock;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The roster of one session, live while the session is running.
 *
 * `isLive` is AttendanceWindow's answer, computed from Clock::now() on the
 * server — the browser never decides that a session is live, it only polls a
 * server that does (BR-07).
 *
 * `hasNoSession` is the empty state of the whole panel and is a first-class
 * published value, so the template asks one question instead of guessing from
 * a null (art. 17).
 *
 * @see BR-07, BR-08, BR-09, BR-23 · PRD §9.9.7 · CONSTITUTION art. 5, art. 11, art. 17
 */
final class AttendanceRoster extends ViewModel
{
    use PresentsFormValues;

    /** No session picked, or the cohort has none yet. */
    public static function none(): self
    {
        return new self([
            'hasNoSession' => true,
            'sessionId' => null,
            'sessionTitle' => '—',
            'startsAt' => null,
            'endsAt' => null,
            'isLive' => false,
            'presentCount' => 0,
            'totalCount' => 0,
            'entries' => collect(),
        ]);
    }

    /**
     * @param  Collection<int, User>  $participants
     * @param  Collection<string, Attendance>  $records  keyed by user id
     */
    public static function of(
        Session $session,
        Collection $participants,
        Collection $records,
        AttendanceWindow $window,
    ): self {
        $entries = $participants
            ->map(static fn (User $participant): RosterEntry => RosterEntry::from(
                $participant,
                $records->get((string) $participant->getKey()),
            ))
            ->values();

        return new self([
            'hasNoSession' => false,
            'sessionId' => (string) $session->getKey(),
            'sessionTitle' => (string) ($session->getAttribute('topic') ?? $session->getAttribute('title') ?? '—'),
            'startsAt' => $window->startsAt($session),
            'endsAt' => $window->endsAt($session),
            'isLive' => $window->isLive($session, Clock::now()),
            'presentCount' => $records->filter(
                static fn (Attendance $record): bool => $record->getAttribute('check_in_at') !== null
            )->count(),
            'totalCount' => $participants->count(),
            'entries' => $entries,
        ]);
    }
}
