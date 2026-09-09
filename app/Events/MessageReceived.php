<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody wrote to a participant inside the platform.
 *
 * The excerpt is carried, not the message id: an e-mail that reproduces a whole
 * conversation defeats the point of having the conversation in the platform,
 * and a message can be edited or reported between the send and the queue run.
 *
 * @see PRD §9.14, §9.16.1 · D-51
 */
final class MessageReceived
{
    use Dispatchable;

    public function __construct(
        public readonly User $recipient,
        public readonly string $senderName,
        public readonly string $threadTitle,
        public readonly string $excerpt,
    ) {}
}
