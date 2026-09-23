<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Which video service an online session's meeting link opens.
 *
 * Recorded playback (`recording_url`) stays Zoom-only by design (D-107): this
 * enum only labels the LIVE join link, which was already a plain redirect to
 * any HTTPS url with no embedding involved (BR-24).
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum SessionPlatform: string
{
    use HasEnumValues;

    case Zoom = 'zoom';
    case GoogleMeet = 'google_meet';
    case Teams = 'teams';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.session_platform.'.$this->value);
    }

    /** The sprite icon id that stands in for this platform's mark. */
    public function icon(): string
    {
        return match ($this) {
            self::Zoom => 'i-zoom',
            self::GoogleMeet => 'i-meet',
            self::Teams => 'i-teams',
        };
    }
}
