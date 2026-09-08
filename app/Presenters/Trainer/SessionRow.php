<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Models\Session;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Attendance\AttendanceWindow;
use App\Support\ViewModel;

/**
 * One session on the trainer's schedule.
 *
 * `startsAt` and `endsAt` are composed by AttendanceWindow from the stored date
 * and the stored Riyadh wall-clock times, which is the same pair BR-01…BR-04
 * measure their windows against — so what the trainer reads here and what the
 * attendance window enforces can never be two different instants (art. 6,
 * art. 11).
 *
 * `hasMeetingUrl` says whether a link exists; the link itself never leaves the
 * server before its window opens, and that guard lives on the participant side
 * (BR-24).
 *
 * @see BR-07, BR-23, BR-24, BR-27 · PRD §9.8, §9.10 · CONSTITUTION art. 6, art. 11
 */
final class SessionRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(Session $session, AttendanceWindow $window): self
    {
        $status = $session->getAttribute('status');
        $status = $status instanceof SessionStatus ? $status : null;

        $type = $session->getAttribute('type');
        $type = $type instanceof SessionType ? $type : null;

        $trainer = self::related($session, 'trainer');
        $trainerProfile = self::related($trainer, 'profile');

        return new self([
            'id' => (string) $session->getKey(),
            'topic' => (string) ($session->getAttribute('topic') ?? $session->getAttribute('title') ?? '—'),
            'date' => $session->getAttribute('date'),
            'startsAt' => $window->startsAt($session),
            'endsAt' => $window->endsAt($session),
            'type' => $type->value ?? '',
            'typeLabel' => $type?->label() ?? '—',
            'trainerName' => (string) (
                $trainerProfile?->getAttribute('full_name_ar')
                ?? self::attr($trainer, 'email')
                ?? '—'
            ),
            'statusLabel' => $status?->label() ?? '—',
            'statusVariant' => self::sessionVariantOf($status),
            'statusIcon' => self::sessionIconOf($status),
            'isCancelled' => $status === SessionStatus::Cancelled,
            'cancellationReason' => (string) ($session->getAttribute('cancellation_reason') ?? '—'),
            'hasMeetingUrl' => is_string($session->getAttribute('zoom_url'))
                && $session->getAttribute('zoom_url') !== '',
        ]);
    }
}
