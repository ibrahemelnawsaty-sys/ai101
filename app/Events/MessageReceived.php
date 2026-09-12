<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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

    /*
     * The queue stores this object, so it must not store a whole model.
     *
     * Without SerializesModels, Laravel PHP-serializes the event into
     * `jobs.payload` — and a User's $attributes carries `password_hash` and
     * `remember_token`. A failed send keeps that row in `failed_jobs` for the
     * fourteen days `queue:prune-failed` allows, and the nightly dump carries it
     * further. With the trait only the class and the key are written, and the
     * row is re-read when the job runs (D-65).
     */
    use SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly string $senderName,
        public readonly string $threadTitle,
        public readonly string $excerpt,
    ) {}
}
