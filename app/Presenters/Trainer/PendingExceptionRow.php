<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Models\AttendanceExceptionRequest;
use App\Support\Dates;
use App\Support\ViewModel;

/**
 * One row of the coordinator's pending excuse-request queue (D-106).
 *
 * @see D-106 · PRD §9.9
 */
final class PendingExceptionRow extends ViewModel
{
    public static function from(AttendanceExceptionRequest $request): self
    {
        $attendance = $request->attendance;
        $session = $attendance?->session;
        $participant = $request->user;
        $profile = self::related($participant, 'profile');

        $participantName = (string) (
            $profile?->getAttribute('full_name_ar') ?? $participant?->getAttribute('email') ?? ''
        );

        $type = $request->getAttribute('type');

        return new self([
            'id' => (string) $request->getKey(),
            'participantName' => $participantName,
            'sessionTitle' => (string) ($session?->getAttribute('topic') ?? $session?->getAttribute('title') ?? ''),
            'sessionDate' => $session === null ? '' : Dates::shortDate($session->getAttribute('date')),
            'typeValue' => $type->value,
            'typeLabel' => $type->label(),
            'reason' => (string) $request->getAttribute('reason'),
            'requestedAt' => Dates::longDate($request->getAttribute('created_at')),
        ]);
    }

    /**
     * Reads a relation without throwing when it — or its owner — is missing:
     * a presenter must still render a row for a participant account that was
     * since removed, rather than take the whole queue down (Article 7).
     */
    private static function related(mixed $owner, string $relation): mixed
    {
        if ($owner === null) {
            return null;
        }

        return $owner->relationLoaded($relation) ? $owner->getRelation($relation) : $owner->{$relation};
    }
}
