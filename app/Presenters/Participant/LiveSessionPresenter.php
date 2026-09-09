<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\SessionStatus;
use App\Http\Controllers\Participant\LiveController;
use App\Models\Session;
use App\Presenters\Support\Present;
use App\Services\Attendance\AttendanceWindow;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * The featured session at the top of the live screen (PRD §9.10).
 *
 * BR-24 in one line: the meeting URL is not here, and it is not in the page.
 * `joinWindowOpen` is the server's answer to «is it time», rendered as a
 * disabled or enabled button; the join endpoint asks the same question again
 * before it hands anything over.
 *
 * @see BR-07, BR-24 · PRD §9.10
 */
final class LiveSessionPresenter extends ViewModel
{
    public static function missing(): self
    {
        return new self([
            'isMissing' => true,
            'id' => null,
            'topic' => null,
            'startsAt' => null,
            'endsAt' => null,
            'trainerName' => null,
            'statusLabel' => null,
            'statusVariant' => 'neutral',
            'joinWindowOpen' => false,
            'joinOpensBeforeMinutes' => self::windowFor($session),
        ]);
    }

    public static function from(Session $session, AttendanceWindow $window, CarbonImmutable $now): self
    {
        $startsAt = $window->startsAt($session);
        $endsAt = $window->endsAt($session);
        $opensAt = $startsAt->subMinutes(self::windowFor($session));
        $isLive = $window->isLive($session, $now);

        $status = $session->getAttribute('status');
        $status = $status instanceof SessionStatus ? $status : SessionStatus::tryFrom((string) $status);

        return new self([
            'isMissing' => false,
            'id' => (string) $session->getKey(),
            'topic' => Present::text($session->getAttribute('topic'))
                ?? (string) $session->getAttribute('title'),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'trainerName' => SessionPresenter::trainerName($session),
            'statusLabel' => $isLive ? SessionStatus::Live->label() : ($status?->label() ?? ''),
            'statusVariant' => $isLive ? 'live' : ($status === SessionStatus::Cancelled ? 'error' : 'info'),
            'joinWindowOpen' => ! $window->isCancelled($session)
                && $now->greaterThanOrEqualTo($opensAt)
                && $now->lessThanOrEqualTo($endsAt),
            'joinOpensBeforeMinutes' => self::windowFor($session),
        ]);
    }
    /**
     * The trainer's own join window for this session, or the platform default.
     * Kept beside the presenter that uses it so the screen and the controller
     * cannot disagree about when a link appears (D-52).
     */
    private static function windowFor(Session $session): int
    {
        $minutes = $session->getAttribute('join_opens_minutes');

        return is_int($minutes) ? $minutes : LiveController::JOIN_OPENS_BEFORE_START_MINUTES;
    }

}
