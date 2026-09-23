<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\SessionDeliveryMode;
use App\Enums\SessionPlatform;
use App\Enums\SessionType;
use App\Models\Session;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * The session editor, open on a new session or an existing one.
 *
 * The date and the two times are Riyadh wall-clock values exactly as the table
 * stores them, so nothing is converted on the way out and nothing is converted
 * on the way back: only Clock ever turns the pair into an instant (art. 11).
 *
 * The meeting link is edited here and is never emitted to a participant page
 * before its window opens — that guard lives on the participant side (BR-24).
 *
 * @see BR-07, BR-23, BR-24 · PRD §9.8, §9.10 · CONSTITUTION art. 11
 */
final class SessionForm extends ViewModel
{
    use PresentsFormValues;

    public static function blank(?string $weekId = null): self
    {
        return new self([
            'exists' => false,
            'id' => null,
            'topic' => '',
            'description' => '',
            'weekId' => $weekId,
            'type' => SessionType::Training->value,
            'trainerId' => null,
            'coordinatorId' => null,
            'deliveryMode' => SessionDeliveryMode::Online->value,
            'platform' => null,
            'locationName' => '',
            'locationMapUrl' => '',
            'roomName' => '',
            'dateValue' => '',
            'startTimeValue' => '',
            'endTimeValue' => '',
            'meetingUrl' => '',
            'meetingPasscode' => '',
            // Null means "use the platform default" (D-52). The editor renders
            // an empty box for it, not a zero.
            'joinOpensMinutes' => null,
        ]);
    }

    public static function from(Session $session): self
    {
        $type = $session->getAttribute('type');
        $deliveryMode = $session->getAttribute('delivery_mode');
        $platform = $session->getAttribute('platform');

        return new self([
            'exists' => true,
            'id' => (string) $session->getKey(),
            'topic' => (string) ($session->getAttribute('topic') ?? $session->getAttribute('title') ?? ''),
            'description' => (string) ($session->getAttribute('description') ?? ''),
            'weekId' => $session->getAttribute('week_id') === null
                ? null
                : (string) $session->getAttribute('week_id'),
            'type' => $type instanceof SessionType ? $type->value : (string) $type,
            'trainerId' => $session->getAttribute('trainer_id') === null
                ? null
                : (string) $session->getAttribute('trainer_id'),
            'coordinatorId' => $session->getAttribute('coordinator_id') === null
                ? null
                : (string) $session->getAttribute('coordinator_id'),
            'deliveryMode' => $deliveryMode instanceof SessionDeliveryMode
                ? $deliveryMode->value
                : (string) ($deliveryMode ?? SessionDeliveryMode::Online->value),
            'platform' => $platform instanceof SessionPlatform ? $platform->value : $platform,
            'locationName' => (string) ($session->getAttribute('location_name') ?? ''),
            'locationMapUrl' => (string) ($session->getAttribute('location_map_url') ?? ''),
            'roomName' => (string) ($session->getAttribute('room_name') ?? ''),
            'dateValue' => self::dateInput($session->getAttribute('date')),
            'startTimeValue' => self::wallTimeInput($session->getAttribute('start_time')),
            'endTimeValue' => self::wallTimeInput($session->getAttribute('end_time')),
            'meetingUrl' => (string) ($session->getAttribute('meeting_url') ?? ''),
            'meetingPasscode' => (string) ($session->getAttribute('meeting_passcode') ?? ''),
            'joinOpensMinutes' => $session->getAttribute('join_opens_minutes'),
        ]);
    }
}
