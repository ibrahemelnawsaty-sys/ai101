<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

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
            'dateValue' => '',
            'startTimeValue' => '',
            'endTimeValue' => '',
            'meetingUrl' => '',
            'meetingPasscode' => '',
        ]);
    }

    public static function from(Session $session): self
    {
        $type = $session->getAttribute('type');

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
            'dateValue' => self::dateInput($session->getAttribute('date')),
            'startTimeValue' => self::wallTimeInput($session->getAttribute('start_time')),
            'endTimeValue' => self::wallTimeInput($session->getAttribute('end_time')),
            'meetingUrl' => (string) ($session->getAttribute('zoom_url') ?? ''),
            'meetingPasscode' => (string) ($session->getAttribute('zoom_passcode') ?? ''),
        ]);
    }
}
