<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\AuditLog;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;
use Illuminate\Support\Facades\Lang;

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
            'actionLabel' => self::translated('admin.audit.actions.'.$action, $action),
            'entityLabel' => self::translated('admin.audit.entities.'.class_basename($entityType), class_basename($entityType)),
            'entityId' => (string) ($entry->getAttribute('entity_id') ?? '—'),
            'ipAddress' => (string) ($entry->getAttribute('ip_address') ?? '—'),
            'userAgent' => (string) ($entry->getAttribute('user_agent') ?? '—'),
            'beforeText' => self::json($entry->getAttribute('before')),
            'afterText' => self::json($entry->getAttribute('after')),
        ]);
    }

    /** A phrase when lang has one, the raw code when it does not. */
    private static function translated(string $key, string $fallback): string
    {
        if ($fallback === '') {
            return '—';
        }

        return Lang::has($key) ? (string) __($key) : $fallback;
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
