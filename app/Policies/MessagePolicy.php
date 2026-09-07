<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;

/**
 * Individual messages. A sender may edit or delete their own message within
 * 15 minutes of sending it, and nobody else may touch it at all (PRD §9.13.2).
 *
 * @see BR-22, BR-33 · PRD §9.13 · CONSTITUTION Art. 22
 */
final class MessagePolicy
{
    use InteractsWithScope;

    /** Editing window after sending, in minutes (PRD §9.13.2). */
    public const EDIT_WINDOW_MINUTES = 15;

    public function view(User $user, Message $message): bool
    {
        $thread = $message->relationLoaded('thread')
            ? $message->thread
            : Thread::query()->find($message->thread_id);

        if ($thread === null) {
            return false;
        }

        return app(ThreadPolicy::class)->view($user, $thread);
    }

    public function update(User $user, Message $message): bool
    {
        return $this->owns($user, (string) $message->sender_id)
            && $this->writesAllowed()
            && $this->withinEditWindow($message);
    }

    public function delete(User $user, Message $message): bool
    {
        if ($this->admin($user) && $this->writesAllowed()) {
            return true;
        }

        return $this->update($user, $message);
    }

    public function report(User $user, Message $message): bool
    {
        return $this->view($user, $message) && $this->writesAllowed();
    }

    public function forceDelete(User $user, Message $message): bool
    {
        return false;
    }

    private function withinEditWindow(Message $message): bool
    {
        $sentAt = $message->created_at;

        if ($sentAt === null) {
            return false;
        }

        $deadline = CarbonImmutable::instance($sentAt)
            ->utc()
            ->addMinutes(self::EDIT_WINDOW_MINUTES);

        return Clock::now()->lessThanOrEqualTo($deadline);
    }
}
