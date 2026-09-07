<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Session;
use App\Presenters\Support\Present;
use App\Services\Attendance\AttendanceWindow;
use App\Support\ViewModel;

/**
 * One past recording in the live screen's list (PRD §9.10).
 *
 * The recording URL is not published: the watch button points at a guarded
 * route that asks the policy and then redirects, so the media host is never
 * named in the page (BR-22, PRD §12.5).
 *
 * @see BR-22 · PRD §9.10
 */
final class RecordingPresenter extends ViewModel
{
    private const SECONDS_PER_MINUTE = 60;

    public static function from(Session $session, AttendanceWindow $window): self
    {
        $startsAt = $window->startsAt($session);
        $endsAt = $window->endsAt($session);

        return new self([
            'id' => (string) $session->getKey(),
            'topic' => Present::text($session->getAttribute('topic'))
                ?? (string) $session->getAttribute('title'),
            'startsAt' => $startsAt,
            'durationMinutes' => intdiv(
                max(0, $endsAt->getTimestamp() - $startsAt->getTimestamp()),
                self::SECONDS_PER_MINUTE,
            ),
        ]);
    }
}
