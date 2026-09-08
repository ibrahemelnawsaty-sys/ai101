<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Models\Profile;
use App\Models\Session;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Attendance\AttendanceWindow;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * One scheduled session, as the schedule and the live screens read it.
 *
 * The meeting link is deliberately absent. `hasMeetingUrl` says only whether a
 * link exists at all; the link itself is handed over by the guarded join
 * endpoint after it re-checks the window, so it cannot be read out of the page
 * source before its time (BR-24, PRD §9.10).
 *
 * Start and end are resolved through AttendanceWindow, which composes the
 * stored Riyadh date and wall-clock times into UTC instants — this presenter
 * never does that arithmetic itself (CONSTITUTION art. 6, art. 11).
 *
 * @see BR-07, BR-22, BR-24 · PRD §9.8, §9.10
 */
final class SessionPresenter extends ViewModel
{
    public static function from(
        Session $session,
        AttendanceWindow $window,
        CarbonImmutable $now,
        bool $isNext = false,
    ): self {
        $status = $session->getAttribute('status');
        $status = $status instanceof SessionStatus ? $status : SessionStatus::tryFrom((string) $status);

        $type = $session->getAttribute('type');
        $type = $type instanceof SessionType ? $type : SessionType::tryFrom((string) $type);

        $isCancelled = $status === SessionStatus::Cancelled;
        $isLive = ! $isCancelled && $window->isLive($session, $now);

        return new self([
            'id' => (string) $session->getKey(),
            // The session's own name. The ICS export already writes it as the
            // event SUMMARY, so the screen prints it too rather than showing the
            // participant one name in the page and another in their calendar.
            'title' => (string) $session->getAttribute('title'),
            'topic' => Present::text($session->getAttribute('topic'))
                ?? (string) $session->getAttribute('title'),
            'type' => $type->value ?? 'training',
            'typeLabel' => $type?->label() ?? '',
            'date' => Present::toDateTime($session->getAttribute('date')),
            'startsAt' => $window->startsAt($session),
            'endsAt' => $window->endsAt($session),
            'trainerName' => self::trainerName($session),
            'isNext' => $isNext,
            'isCancelled' => $isCancelled,
            'statusLabel' => $isLive
                ? SessionStatus::Live->label()
                : ($status?->label() ?? ''),
            'statusVariant' => self::statusVariant($status, $isLive),
            'statusIcon' => self::statusIcon($status, $isLive),
            'cancellationReason' => Present::text($session->getAttribute('cancellation_reason')),
            // No replacement-date column exists yet (PROJECT-CONTRACT §4); the
            // view only prints this when it is present, so absent stays absent.
            'replacementStartsAt' => null,
            'hasMeetingUrl' => Present::text($session->getAttribute('zoom_url')) !== null,
        ]);
    }

    /**
     * The trainer's Arabic name, and only from an already-loaded relation:
     * touching an unloaded one here would be the N+1 that art. 19 forbids.
     */
    public static function trainerName(Session $session): ?string
    {
        if (! $session->relationLoaded('trainer')) {
            return null;
        }

        $trainer = $session->getRelation('trainer');

        if (! $trainer instanceof User) {
            return null;
        }

        if ($trainer->relationLoaded('profile')) {
            $profile = $trainer->getRelation('profile');

            if ($profile instanceof Profile) {
                $name = Present::text($profile->getAttribute('full_name_ar'));

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return Present::text($trainer->getAttribute('email'));
    }

    private static function statusVariant(?SessionStatus $status, bool $isLive): string
    {
        if ($isLive) {
            return 'live';
        }

        return match ($status) {
            SessionStatus::Cancelled => 'error',
            SessionStatus::Completed => 'neutral',
            SessionStatus::Live => 'live',
            default => 'info',
        };
    }

    private static function statusIcon(?SessionStatus $status, bool $isLive): string
    {
        if ($isLive) {
            return 'video';
        }

        return match ($status) {
            SessionStatus::Cancelled => 'warn',
            SessionStatus::Completed => 'check',
            default => 'clock',
        };
    }
}
