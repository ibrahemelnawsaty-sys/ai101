<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The notification types the platform knows, and who receives each.
 *
 * The catalogue is `notifications.types` in the lang files — the one list the
 * preference request validates against and the settings matrix renders. The
 * audience of each type is PRD §9.16.1's recipient column, written here once:
 * the trainee receives everything except the two addressed to the trainer
 * ("new submission" and "incomplete attendance"), and a message reaches
 * whoever it was sent to, whatever their role.
 *
 * WHY THIS EXISTS
 * The preferences screen listed the account's stored rows, and only seeded
 * demo accounts ever had rows — so every real, invited trainee opened an EMPTY
 * table and could switch nothing. And two seeded rows carried slugs that are
 * not types at all, so the table printed raw keys and every save was refused
 * without a word (D-78).
 *
 * Security letters (a password change, a sign-in from a new device) are not
 * types here: they are never suppressible, by design (D-66).
 *
 * @see PRD §9.16, §9.16.1 · BR-33 · D-66, D-78
 */
final class NotificationTypes
{
    /** Types addressed to the trainer, per PRD §9.16.1. */
    private const TRAINER = ['submission_new', 'attendance_incomplete', 'message_received'];

    /** Types an administrator receives: messages addressed to them. */
    private const ADMIN = ['message_received'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $types = __('notifications.types');

        if (! is_array($types)) {
            return [];
        }

        $known = [];

        foreach ($types as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $known[$key] = $entry;
            }
        }

        return $known;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The types a shell role receives, in catalogue order.
     *
     * @param  string  $role  'admin' | 'trainer' | 'participant' (RoleResolver::shellRole)
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        $keys = self::keys();

        return array_values(match ($role) {
            'admin' => array_intersect($keys, self::ADMIN),
            'trainer' => array_intersect($keys, self::TRAINER),
            default => array_diff($keys, array_diff(self::TRAINER, ['message_received'])),
        });
    }
}
