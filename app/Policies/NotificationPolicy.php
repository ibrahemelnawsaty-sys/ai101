<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Notifications belong to exactly one person. Marking one read is a write on
 * that person's trace, so it is refused for the whole duration of a preview —
 * an admin looking at an account must not consume its unread badge (BR-34).
 *
 * @see BR-22, BR-34 · PRD §9.16, §4.5.2 · CONSTITUTION Art. 23
 */
final class NotificationPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Notification $notification): bool
    {
        return $this->owns($user, (string) $notification->user_id);
    }

    public function markRead(User $user, Notification $notification): bool
    {
        return $this->owns($user, (string) $notification->user_id) && $this->writesAllowed();
    }

    public function markAllRead(User $user): bool
    {
        return $this->roles->isActive($user) && $this->writesAllowed();
    }

    public function updatePreferences(User $user): bool
    {
        return $this->roles->isActive($user) && $this->writesAllowed();
    }

    public function delete(User $user, Notification $notification): bool
    {
        return $this->owns($user, (string) $notification->user_id) && $this->writesAllowed();
    }
}
