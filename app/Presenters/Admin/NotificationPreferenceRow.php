<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Presenters\Participant\PreferencePresenter;
use App\Support\NotificationTypes;
use App\Support\ViewModel;

/**
 * One row of the default notification matrix (PRD §9.16.1).
 *
 * The event list is read from lang/{ar,en}/notifications.php, which is already the
 * single source for what the platform can notify about — a second list in a
 * config file would drift from it (art. 6).
 *
 * There is no table for PLATFORM-WIDE defaults in PROJECT-CONTRACT §4: the only
 * table is the per-user `notification_preferences`, and no row means yes
 * (`MailPreferences`). So the screen SHOWS how it is — every event is on by default
 * in both channels, and the types `PreferencePresenter::ALWAYS_ON` names cannot be
 * stopped by the person — and offers no control: a switch with nothing behind it
 * was removed (D-148).
 *
 * @see BR-31, BR-36 · PRD §9.16.1, §9.18 · CONSTITUTION art. 4, art. 6 · D-148
 */
final class NotificationPreferenceRow extends ViewModel
{
    /**
     * @param  array<string, mixed>  $type  one entry of notifications.types
     */
    public static function of(string $key, array $type): self
    {
        return new self([
            'key' => $key,
            'label' => is_string($type['label'] ?? null) ? $type['label'] : $key,
            // The body is a template with :placeholders the matrix has no
            // values for; it printed "Session :session starts soon" (D-78).
            'description' => is_string($type['body'] ?? null)
                ? (string) preg_replace('/:[a-z_]+/', '…', $type['body'])
                : '',
            // Always sent, whatever the person chose — the same list the preferences screen locks.
            'locked' => in_array($key, PreferencePresenter::ALWAYS_ON, true),
        ]);
    }

    /**
     * Every notifiable event the platform knows about.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function all(): \Illuminate\Support\Collection
    {
        $rows = [];

        foreach (NotificationTypes::all() as $key => $type) {
            $rows[] = self::of($key, $type);
        }

        return collect($rows);
    }
}
