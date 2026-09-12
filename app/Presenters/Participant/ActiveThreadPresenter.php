<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\ThreadType;
use App\Models\Cohort;
use App\Models\Thread;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The open conversation (PRD §9.13).
 *
 * `canPost` mirrors two server rules and invents neither: the announcements
 * channel is read-only for participants, and a trainer may lock a thread. The
 * send endpoint refuses both again on arrival, so hiding the composer is a
 * courtesy and not the guard (art. 5).
 *
 * `readOnlyReason` is always populated when `canPost` is false — a control that
 * disappears without a sentence is exactly what PRD §9.9.4 forbids elsewhere
 * and the same courtesy applies here.
 *
 * @see BR-22, BR-33, BR-34 · PRD §9.13
 */
final class ActiveThreadPresenter extends ViewModel
{
    /**
     * @param  Collection<int, MessagePresenter>  $messages
     */
    public static function from(Thread $thread, Collection $messages, bool $isImpersonating, ?User $viewer = null): self
    {
        $type = ThreadPresenter::typeOf($thread);
        $isLocked = (bool) $thread->getAttribute('is_locked');
        $isAnnouncement = $type === ThreadType::Announcement;

        return new self([
            'id' => (string) $thread->getKey(),
            'title' => ThreadPresenter::titleFor($thread, $type, $viewer),
            'subtitle' => self::subtitle($thread, $type),
            'avatarVariant' => ThreadPresenter::avatarVariant($type),
            'type' => $type->value ?? ThreadType::Group->value,
            'isLocked' => $isLocked,
            'canPost' => ! $isAnnouncement && ! $isLocked && ! $isImpersonating,
            'readOnlyReason' => self::readOnlyReason($isAnnouncement, $isLocked, $isImpersonating),
            'messages' => $messages,
            // What the live update compares: when the newest message is the
            // same, nothing on screen needs to change (PRD §9.13.2).
            'latestMessageId' => (string) ($messages->last()?->get('id') ?? ''),
        ]);
    }

    private static function subtitle(Thread $thread, ?ThreadType $type): string
    {
        if ($thread->relationLoaded('cohort')) {
            $cohort = $thread->getRelation('cohort');

            if ($cohort instanceof Cohort) {
                $name = Present::text($cohort->getAttribute('name'));

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return $type?->label() ?? '';
    }

    private static function readOnlyReason(bool $isAnnouncement, bool $isLocked, bool $isImpersonating): ?string
    {
        if ($isImpersonating) {
            return (string) __('profile.preview_readonly_body');
        }

        if ($isAnnouncement) {
            return (string) __('messages.errors.announcement_readonly');
        }

        return $isLocked ? (string) __('messages.errors.thread_locked') : null;
    }
}
