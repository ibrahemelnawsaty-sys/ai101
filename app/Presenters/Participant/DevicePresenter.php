<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Presenters\Support\Present;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * One live session in the account screen's signed-in-devices list (PRD §9.3.2).
 *
 * Only the facts a person needs in order to recognise a device of their own.
 * The session payload and the session id are never published — the id is the
 * credential itself, and the list exists so someone can spot a sign-in they do
 * not recognise, not so it can be replayed.
 *
 * @see BR-22, BR-29 · PRD §9.3.2 · CONSTITUTION art. 24
 */
final class DevicePresenter extends ViewModel
{
    public static function from(object $row): self
    {
        $lastActivity = property_exists($row, 'last_activity') ? $row->last_activity : null;

        return new self([
            'deviceLabel' => self::label(property_exists($row, 'user_agent') ? $row->user_agent : null),
            'ipAddress' => Present::text(property_exists($row, 'ip_address') ? $row->ip_address : null)
                ?? (string) __('app.unknown'),
            'lastActiveAt' => is_numeric($lastActivity)
                ? CarbonImmutable::createFromTimestampUTC((int) $lastActivity)
                : Present::toDateTime($lastActivity),
            'isCurrent' => property_exists($row, 'is_current') && (bool) $row->is_current,
        ]);
    }

    /**
     * A coarse, honest name for the browser and platform. Deliberately not a
     * fingerprint: enough to recognise «my phone», not enough to profile.
     */
    private static function label(mixed $userAgent): string
    {
        $agent = Present::text($userAgent);

        if ($agent === null) {
            return (string) __('profile.unknown_device');
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        $parts = array_values(array_filter([$browser, $platform]));

        return $parts === [] ? (string) __('profile.unknown_device') : implode(' · ', $parts);
    }
}
