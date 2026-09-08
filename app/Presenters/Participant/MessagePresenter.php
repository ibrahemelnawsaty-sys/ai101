<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * One message in a thread (PRD §9.13).
 *
 * `canEdit` is a fifteen-minute courtesy the server also enforces: the edit
 * endpoint refuses a message that is older, and refuses one that is not the
 * sender's, so an enabled link here proves nothing on its own (art. 5).
 *
 * `isRead` is only meaningful in a trainer DM, which is the only place the
 * template prints it. During an account preview no receipt is written at all,
 * and that decision belongs to the controller, not here (BR-34).
 *
 * @see BR-22, BR-34 · PRD §9.13
 */
final class MessagePresenter extends ViewModel
{
    /** PRD §9.13 - a message may be edited for fifteen minutes after sending. */
    public const EDIT_WINDOW_MINUTES = 15;

    public static function from(
        Message $message,
        User $viewer,
        CarbonImmutable $now,
        ?\DateTimeInterface $otherPartyReadAt,
    ): self {
        $senderId = (string) $message->getAttribute('sender_id');
        $isMine = $senderId === (string) $viewer->getKey();
        $sentAt = Present::toDateTime($message->getAttribute('sent_at'));

        return new self([
            'id' => (string) $message->getKey(),
            'isMine' => $isMine,
            'authorName' => self::authorName($message),
            'body' => (string) $message->getAttribute('body'),
            'attachments' => FilePresenter::collect($message->getAttribute('attachments')),
            'sentAt' => $sentAt,
            'isRead' => self::isRead($sentAt, $otherPartyReadAt),
            'canEdit' => $isMine && self::withinEditWindow($sentAt, $now),
        ]);
    }

    private static function authorName(Message $message): string
    {
        if (! $message->relationLoaded('sender')) {
            return '';
        }

        $sender = $message->getRelation('sender');

        if (! $sender instanceof User) {
            return '';
        }

        if ($sender->relationLoaded('profile')) {
            $profile = $sender->getRelation('profile');

            if ($profile instanceof Profile) {
                $name = Present::text($profile->getAttribute('full_name_ar'));

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return (string) $sender->getAttribute('email');
    }

    private static function isRead(?\DateTimeInterface $sentAt, ?\DateTimeInterface $readAt): bool
    {
        if ($sentAt === null || $readAt === null) {
            return false;
        }

        return $readAt->getTimestamp() >= $sentAt->getTimestamp();
    }

    private static function withinEditWindow(?\DateTimeInterface $sentAt, CarbonImmutable $now): bool
    {
        if ($sentAt === null) {
            return false;
        }

        $elapsed = $now->getTimestamp() - $sentAt->getTimestamp();

        return $elapsed >= 0 && $elapsed <= self::EDIT_WINDOW_MINUTES * 60;
    }
}
