<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Broadcast;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Writing to a whole cohort is an administrator's act, and never from inside
 * a preview: a preview may not write (BR-33). Nothing sent is ever edited or
 * removed — the history is the record of what trainees were told (D-87).
 *
 * @see BR-28, BR-33 · PRD §9.18 · D-87
 */
final class BroadcastPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function create(User $user): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function update(User $user, Broadcast $broadcast): bool
    {
        return false;
    }

    public function delete(User $user, Broadcast $broadcast): bool
    {
        return false;
    }

    public function forceDelete(User $user, Broadcast $broadcast): bool
    {
        return false;
    }
}
