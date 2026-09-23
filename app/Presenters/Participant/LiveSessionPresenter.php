<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\SessionPlatform;
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
 * The passcode is, but only once the join window is open (PRD §9.10).
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
            'passcode' => null,
            'platformLabel' => null,
            'platformIcon' => null,
            // There is no session here to ask, so the platform default is the
            // only honest answer. A blind search-and-replace once put a
            // per-session lookup in this method, which has no $session — and the whole
            // live screen returned 500 for exactly the case it exists to
            // handle: a cohort with no session yet (D-52 correction).
            'joinOpensBeforeMinutes' => LiveController::JOIN_OPENS_BEFORE_START_MINUTES,
        ]);
    }

    /**
     * `$mayJoin` is the caller's answer to SessionPolicy::revealJoinLink — the
     * question the join endpoint asks. The page can feature a session to an
     * account the endpoint refuses (a pending or rejected registrant, a
     * withdrawn trainee), and the passcode must not reach them where the link
     * cannot (BR-24). It defaults to no.
     */
    public static function from(Session $session, AttendanceWindow $window, CarbonImmutable $now, bool $mayJoin = false): self
    {
        $startsAt = $window->startsAt($session);
        $endsAt = $window->endsAt($session);
        $isLive = $window->isLive($session, $now);

        $status = $session->getAttribute('status');
        $status = $status instanceof SessionStatus ? $status : SessionStatus::tryFrom((string) $status);

        $open = LiveController::joinWindowOpen($session, $window, $now);
        $passcode = Present::text($session->getAttribute('meeting_passcode'));

        $platform = $session->getAttribute('platform');
        $platform = $platform instanceof SessionPlatform ? $platform : null;

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
            // The endpoint's own rule, asked rather than copied (D-52, D-75).
            'joinWindowOpen' => $open,
            // PRD §9.10: the meeting passcode is shown, with a copy button,
            // WHEN the link is — never before. The same rule that withholds the
            // URL withholds this (BR-24), so it is null until the window opens.
            // The page used to reference a `passcode` no component provided;
            // Alpine threw on every render and the passcode never appeared (D-86).
            'passcode' => $open && $mayJoin ? $passcode : null,
            'platformLabel' => $platform?->label(),
            'platformIcon' => $platform?->icon(),
            'joinOpensBeforeMinutes' => LiveController::joinWindowMinutes($session),
        ]);
    }
}
