<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\ThreadType;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One entry in the thread list beside the conversation (PRD §9.13).
 *
 * The unread count is passed in, never counted here: it is read from this
 * account's own `thread_participants.last_read_at`, which is the only row that
 * may speak for it (BR-22).
 *
 * @see BR-22, BR-34 · PRD §9.13
 */
final class ThreadPresenter extends ViewModel
{
    public static function from(Thread $thread, int $unreadCount, ?User $viewer = null): self
    {
        $type = self::typeOf($thread);

        return new self([
            'id' => (string) $thread->getKey(),
            'title' => self::titleFor($thread, $type, $viewer),
            'avatarVariant' => self::avatarVariant($type),
            'icon' => self::icon($type),
            'previewLine' => self::preview($thread),
            'unreadCount' => $unreadCount,
        ]);
    }

    public static function typeOf(Thread $thread): ?ThreadType
    {
        $type = $thread->getAttribute('type');

        return $type instanceof ThreadType ? $type : ThreadType::tryFrom((string) $type);
    }

    public static function titleOf(Thread $thread, ?ThreadType $type): string
    {
        return Present::text($thread->getAttribute('title')) ?? ($type?->label() ?? '');
    }

    /**
     * A direct thread is titled by the OTHER person: a trainer's list of sixty
     * conversations all labelled "trainer conversation" is not a list (D-82).
     * Needs `users.profile` loaded; without it, or for any other type, the
     * type's own title.
     */
    public static function titleFor(Thread $thread, ?ThreadType $type, ?User $viewer): string
    {
        if ($type === ThreadType::TrainerDm && $viewer instanceof User && $thread->relationLoaded('users')) {
            foreach ($thread->getRelation('users') as $member) {
                if ($member instanceof User && ! $member->is($viewer)) {
                    $profile = $member->relationLoaded('profile') ? $member->getRelation('profile') : null;
                    $name = $profile instanceof \App\Models\Profile ? Present::text($profile->getAttribute('full_name_ar')) : null;

                    return $name ?? (string) $member->getAttribute('email');
                }
            }
        }

        return self::titleOf($thread, $type);
    }

    /** The avatar component accepts default | teal | neutral only. */
    public static function avatarVariant(?ThreadType $type): string
    {
        return match ($type) {
            ThreadType::Announcement => 'neutral',
            ThreadType::Group => 'teal',
            default => 'default',
        };
    }

    public static function icon(?ThreadType $type): string
    {
        return match ($type) {
            ThreadType::Announcement => 'bell',
            ThreadType::Group => 'users',
            default => 'chat',
        };
    }

    private static function preview(Thread $thread): string
    {
        if (! $thread->relationLoaded('latestMessage')) {
            return (string) __('messages.thread_empty_title');
        }

        $latest = $thread->getRelation('latestMessage');

        if (! $latest instanceof Message) {
            return (string) __('messages.thread_empty_title');
        }

        return Present::text($latest->getAttribute('body')) ?? (string) __('messages.thread_empty_title');
    }
}
