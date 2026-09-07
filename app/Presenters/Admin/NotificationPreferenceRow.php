<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Support\ViewModel;

/**
 * One row of the default notification matrix (PRD §9.16.1).
 *
 * The event list is read from lang/{ar,en}/notifications.php, which is already the
 * single source for what the platform can notify about — a second list in a
 * config file would drift from it (art. 6).
 *
 * There is no table for PLATFORM-WIDE defaults in PROJECT-CONTRACT §4: the only
 * table is the per-user `notification_preferences`. The screen therefore shows
 * every event with both channels on and both editable, and the controller
 * records the administrator's choice in the append-only trail rather than
 * writing to a table this slice would have had to invent (art. 4). The gap is
 * reported with this slice.
 *
 * @see BR-31, BR-36 · PRD §9.16.1, §9.18 · CONSTITUTION art. 4, art. 6
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
            'description' => is_string($type['body'] ?? null) ? $type['body'] : '',
            'platform' => true,
            'email' => true,
            'platformEditable' => true,
            'emailEditable' => true,
        ]);
    }

    /**
     * Every notifiable event the platform knows about.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function all(): \Illuminate\Support\Collection
    {
        $types = __('notifications.types');

        if (! is_array($types)) {
            return collect();
        }

        $rows = [];

        foreach ($types as $key => $type) {
            if (is_string($key) && is_array($type)) {
                $rows[] = self::of($key, $type);
            }
        }

        return collect($rows);
    }
}
