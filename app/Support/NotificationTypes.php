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
 * @see PRD §9.16, §9.16.1 · BR-33 · D-66, D-78, D-117, D-118, D-124
 */
final class NotificationTypes
{
    /** Types addressed to the trainer, per PRD §9.16.1 — and the guide's publication (D-127). */
    private const TRAINER = ['submission_new', 'attendance_incomplete', 'message_received', 'final_project_guide_published_staff'];

    /**
     * D-127 — the final project's staff notices: what the primary coordinator
     * is asked to publish, and the guide reaching the cohort's staff. A
     * trainee never receives them, so their screen never lists them; a
     * coordinator's screen lists them beside everything it listed before.
     */
    private const COORDINATOR = ['final_project_available', 'final_project_guide_available', 'final_project_guide_published_staff'];

    /**
     * Types an administrator receives: messages addressed to them, and the
     * support tickets that reach them (D-124).
     */
    private const ADMIN = ['message_received', 'support_ticket_team'];

    /**
     * Types the system administrator receives: messages in the shared inbox
     * with the general supervisors (D-118), and the support tickets that reach
     * them (D-124) — nothing else: the role reaches no cohort, so every other
     * type concerns someone else (D-117). Security letters still reach them —
     * they are not types, and never suppressible.
     */
    private const SYSTEM_ADMIN = ['message_received', 'support_ticket_team'];

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
     * @param  string  $role  'admin' | 'system_admin' | 'trainer' | 'coordinator' | 'participant'
     *                        (RoleResolver::shellRole)
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        $keys = self::keys();

        $trainee = array_diff($keys, array_diff(self::TRAINER, ['message_received']), self::COORDINATOR);

        return array_values(match ($role) {
            'admin' => array_intersect($keys, self::ADMIN),
            'system_admin' => array_intersect($keys, self::SYSTEM_ADMIN),
            'trainer' => array_intersect($keys, self::TRAINER),
            'coordinator' => array_intersect($keys, array_merge($trainee, self::COORDINATOR)),
            default => $trainee,
        });
    }
}
