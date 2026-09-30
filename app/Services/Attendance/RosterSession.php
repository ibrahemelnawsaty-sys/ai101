<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Session;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which session the trainer's attendance roster opens on, when the address does
 * not say (D-142).
 *
 *   1. the session that is LIVE — AttendanceWindow::isLive (S ≤ now ≤ E, false for
 *      a cancelled one): the definition the live pill and the poll already use,
 *      asked and not restated;
 *   2. else the NEXT session — the earliest that has not started;
 *   3. else the LAST session held.
 *
 * It only chooses what to SHOW. It records nothing, opens no window, and decides
 * no one's status: a session named in the address always wins, and the trainer
 * may pick any other from the list. A cancelled session is never the default
 * (nobody opens the roster of a session that will not happen); if every session
 * is cancelled the last of them is shown so the screen is never empty of a roster
 * the cohort has (the assumption is recorded in D-142).
 *
 * The clock is a parameter, never read here (BR-07).
 *
 * @see BR-07, BR-08 · PRD §9.9.7 · D-142
 */
final class RosterSession
{
    public function __construct(private readonly AttendanceWindow $window) {}

    /**
     * @param  Collection<int, Session>  $sessions  any order
     */
    public function defaultFor(Collection $sessions, CarbonImmutable $at): ?Session
    {
        if ($sessions->isEmpty()) {
            return null;
        }

        $byStart = $sessions
            ->sortBy(fn (Session $session): int => $this->window->startsAt($session)->getTimestamp())
            ->values();

        $live = $byStart->first(fn (Session $session): bool => $this->window->isLive($session, $at));

        if ($live instanceof Session) {
            return $live;
        }

        $held = $byStart->reject(fn (Session $session): bool => $this->window->isCancelled($session))->values();

        $next = $held->first(fn (Session $session): bool => $this->window->startsAt($session)->greaterThan($at));

        if ($next instanceof Session) {
            return $next;
        }

        $last = $held->isNotEmpty() ? $held->last() : $byStart->last();

        return $last instanceof Session ? $last : null;
    }
}
