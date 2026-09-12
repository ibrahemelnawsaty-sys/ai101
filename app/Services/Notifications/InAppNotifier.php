<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Presenters\Participant\PreferencePresenter;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;

/**
 * The one place an in-app notification is written.
 *
 * WHY THIS EXISTS
 * The preferences screen offers two switches per kind of notice — the platform
 * bell and e-mail — and stores both. D-66 made every sender read the e-mail
 * switch. The bell switch was read by nobody: three writers (final project,
 * grades, attendance) built rows directly, each with its own copy of the same
 * eleven setAttribute calls, and none of them asked. A trainee who silenced a
 * kind of notice on the platform went on receiving it there (D-68).
 *
 * WHAT IT DECIDES
 *   · ALWAYS_ON types (an enrolment decision, a certificate) are written for
 *     everyone — the same list the screen locks and the request forces on.
 *   · everything else skips whoever switched the platform channel off for it,
 *     in ONE query for the whole audience.
 *   · no preference row means yes (PRD §9.16).
 *
 * It writes synchronously, where the action happens, as every in-app writer
 * here always has: a queued writer retried after a partial run would write the
 * same notice twice.
 *
 * @see PRD §9.16, §9.16.1 · BR-33 · D-66, D-68
 */
final class InAppNotifier
{
    /**
     * @param  iterable<int, mixed>  $userIds
     * @return int how many rows were written
     */
    public function notify(
        iterable $userIds,
        string $type,
        string $title,
        string $body,
        ?string $link,
        ?CarbonImmutable $at = null,
    ): int {
        $ids = [];

        foreach ($userIds as $id) {
            $ids[(string) $id] = true;
        }

        $ids = array_keys($ids);

        if ($ids === []) {
            return 0;
        }

        if (! in_array($type, PreferencePresenter::ALWAYS_ON, true)) {
            $muted = NotificationPreference::query()
                ->whereIn('user_id', $ids)
                ->where('type', $type)
                ->where('in_app_enabled', false)
                ->pluck('user_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $ids = array_values(array_diff($ids, $muted));
        }

        $at ??= Clock::now();

        foreach ($ids as $id) {
            $notification = new Notification;
            $notification->setAttribute('user_id', $id);
            $notification->setAttribute('type', $type);
            $notification->setAttribute('title', $title);
            $notification->setAttribute('body', $body);
            $notification->setAttribute('link', $link);
            $notification->setAttribute('is_read', false);
            $notification->setAttribute('read_at', null);
            $notification->setAttribute('channel', 'in_app');
            $notification->setAttribute('created_at', $at);
            $notification->setAttribute('updated_at', $at);
            $notification->save();
        }

        return count($ids);
    }
}
