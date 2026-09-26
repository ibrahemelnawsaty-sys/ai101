<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ThreadType;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;
use App\Services\Messages\ConversationRules;

/**
 * Conversations. Membership of the thread — not membership of the cohort — is
 * what grants access, so a participant can never read another participant's
 * private thread with the trainer by changing the id (BR-22).
 *
 * D-118 took away the two role doors that were left: the general supervisor
 * read ANY thread and a trainer read any thread of their cohort — another
 * trainer's direct line included — by its id. The owner's rule is "each
 * person sees their own conversations only"; the system administrator sees
 * someone else's only through the audited preview. `view` is the PHP form of
 * Thread::scopeVisibleTo, which the list and the badge use.
 *
 * Starting a conversation is ConversationRules' to decide (start, startInbox);
 * replying is any member's (post) — which is how the trainer answers a trainee
 * who wrote first without being able to open the conversation himself.
 *
 * Announcement channels are write-only for trainers and admins; a locked group
 * thread accepts no new messages from participants (PRD §9.13.1).
 *
 * @see BR-22, BR-23, BR-33 · PRD §9.13 · CONSTITUTION Art. 22 · D-118
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

        if ($thread->isInbox()) {
            return $this->roles->isSystemAdmin($user)
                || ($thread->isInboxOwner($user) && $this->isMember($user, $thread));
        }

        // The system administrator holds no conversation outside the inbox,
        // whatever row an earlier role left behind (D-117).
        if ($this->roles->isSystemAdmin($user)) {
            return false;
        }

        return $this->isMember($user, $thread);
    }

    /** Start a conversation with $recipient — ConversationRules decides (D-118). */
    public function start(User $user, User $recipient): bool
    {
        return $this->writesAllowed() && app(ConversationRules::class)->mayStartWith($user, $recipient);
    }

    /** Write to the system administrators' shared inbox (D-118). */
    public function startInbox(User $user): bool
    {
        return $this->writesAllowed() && app(ConversationRules::class)->mayWriteToInbox($user);
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

        // Whoever may read it may answer in it (D-118): view is membership,
        // or the inbox for a system administrator.
        return true;
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
        return $this->view($user, $thread) && $this->isMember($user, $thread) && $this->writesAllowed();
    }

    private function isMember(User $user, Thread $thread): bool
    {
        return ThreadParticipant::query()
            ->where('thread_id', $thread->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
