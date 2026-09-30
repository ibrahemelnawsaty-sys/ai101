<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\AuditLog;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * One entry of the append-only audit trail.
 *
 * The action and the entity are machine codes — `user.role_changed`,
 * `App\Models\Certificate` — and they are translated when lang/ar/admin.php has
 * a phrase for them and printed verbatim when it does not. Printing the raw
 * code is deliberate: it is honest, it is Latin, and it never fabricates a
 * translation that would misdescribe what happened (art. 4, art. 15).
 *
 * `beforeText` and `afterText` are JSON the trail recorded; they go through the
 * escaping echo only, never the raw one, because they hold values a user once
 * typed (art. 24).
 *
 * @see BR-27 · PRD §4.5.3, §9.18 · CONSTITUTION art. 8, art. 15, art. 24
 */
final class AuditEntry extends ViewModel
{
    use PresentsFormValues;

    public static function from(AuditLog $entry): self
    {
        $actor = self::related($entry, 'actor');
        $profile = self::related($actor, 'profile');

        $action = (string) $entry->getAttribute('action');
        $entityType = (string) ($entry->getAttribute('entity_type') ?? '');

        return new self([
            'id' => (string) $entry->getKey(),
            'at' => $entry->getAttribute('created_at'),
            'actorName' => (string) (
                $profile?->getAttribute('full_name_ar')
                ?? self::attr($actor, 'email')
                ?? '—'
            ),
            'actionLabel' => self::actionLabel($action),
            'entityLabel' => self::entityLabel($entityType),
            'entityId' => (string) ($entry->getAttribute('entity_id') ?? '—'),
            'ipAddress' => (string) ($entry->getAttribute('ip_address') ?? '—'),
            'userAgent' => (string) ($entry->getAttribute('user_agent') ?? '—'),
            'beforeText' => self::json($entry->getAttribute('before')),
            'afterText' => self::json($entry->getAttribute('after')),
        ]);
    }

    /** What a recorded action code means; the code itself when lang has no phrase for it. */
    public static function actionLabel(string $code): string
    {
        return self::phrase('actions', $code);
    }

    /** What a recorded entity type is called; the type itself when lang has no name for it. */
    public static function entityLabel(string $type): string
    {
        return self::phrase('entities', class_basename($type));
    }

    /**
     * The codes are keyed WITH their dots (`certificate.revoked`), so the group is read
     * whole and indexed, not looked up by a dotted path that would nest them.
     */
    private static function phrase(string $group, string $code): string
    {
        if ($code === '') {
            return '—';
        }

        $map = trans('admin.audit.'.$group);

        return is_array($map) && isset($map[$code]) && is_string($map[$code]) ? $map[$code] : $code;
    }

    /** Pretty JSON, or null so the detail panel can say "none" instead. */
    private static function json(mixed $payload): ?string
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
