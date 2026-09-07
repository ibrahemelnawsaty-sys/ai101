<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ThreadType;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Conversations. Membership of the thread — not membership of the cohort — is
 * what grants access, so a participant can never read another participant's
 * private thread with the trainer by changing the id (BR-22).
 *
 * Announcement channels are write-only for trainers and admins; a locked group
 * thread accepts no new messages from participants (PRD §9.13.1).
 *
 * @see BR-22, BR-23, BR-33 · PRD §9.13 · CONSTITUTION Art. 22
 */
final class ThreadPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Thread $thread): bool
    {
        if (! $this->roles->isActive($user)) {
            return false;
        }

        return $this->isMember($user, $thread) || $this->staffOf($user, (string) $thread->cohort_id);
    }

    public function post(User $user, Thread $thread): bool
    {
        if (! $this->writesAllowed() || ! $this->view($user, $thread)) {
            return false;
        }

        if ($thread->type === ThreadType::Announcement) {
            return $this->staffOf($user, (string) $thread->cohort_id);
        }

        if ((bool) $thread->getAttribute('is_locked')) {
            return $this->staffOf($user, (string) $thread->cohort_id);
        }

        return $this->isMember($user, $thread);
    }

    public function lock(User $user, Thread $thread): bool
    {
        return $this->staffOf($user, (string) $thread->cohort_id) && $this->writesAllowed();
    }

    /** Muting is a personal preference and is refused during a preview (BR-34). */
    public function mute(User $user, Thread $thread): bool
    {
        return $this->isMember($user, $thread) && $this->writesAllowed();
    }

    public function markRead(User $user, Thread $thread): bool
    {
        return $this->isMember($user, $thread) && $this->writesAllowed();
    }

    private function isMember(User $user, Thread $thread): bool
    {
        return ThreadParticipant::query()
            ->where('thread_id', $thread->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
