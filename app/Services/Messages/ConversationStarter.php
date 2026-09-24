<?php

declare(strict_types=1);

namespace App\Services\Messages;

use App\Enums\ThreadType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens the one conversation between two people, or between a general
 * supervisor and the system administrators' shared inbox (D-118).
 *
 * WHO may open which is ConversationRules' — asked by the policy before this
 * runs. This class only guarantees the shape:
 *
 *   · one conversation per pair. A trainee and their trainer already have
 *     the provisioned direct line (PRD §9.13, D-82): that one is opened, not
 *     a second. Anything else is found by `threads.pair_key`, which the
 *     database keeps unique — two people starting at the same instant land in
 *     the same row, the second request reading what the first wrote.
 *   · an inbox conversation is keyed by its supervisor, whoever starts it: a
 *     system administrator writing to a supervisor, and that supervisor
 *     writing to "the system administrators", are the same conversation.
 *   · every active system administrator is a member of every inbox
 *     conversation. Access is by role (Thread::scopeVisibleTo); the member
 *     row carries each administrator's read marker and e-mail throttle, and
 *     is added for one who arrives later the first time they open their
 *     messages (joinInbox).
 *
 * @see BR-22 · PRD §9.13 · D-82, D-118
 */
final class ConversationStarter
{
    /** The conversation between $from and $to, created on first use. */
    public function withPerson(User $from, User $to): Thread
    {
        if ($from->role === UserRole::SystemAdmin) {
            return $this->inboxOf($to, $from);
        }

        $existing = $this->provisionedLine($from, $to);

        if ($existing instanceof Thread) {
            return $existing;
        }

        $thread = $this->firstOrCreate(Thread::pairKeyFor($from, $to), [
            'cohort_id' => null,
            'type' => ThreadType::Direct->value,
            'inbox' => null,
            'title' => null,
            'created_by' => $from->getKey(),
            'is_locked' => false,
        ]);

        $this->join($thread, (string) $from->getKey());
        $this->join($thread, (string) $to->getKey());

        return $thread;
    }

    /** A general supervisor's conversation with the system administrators. */
    public function withInbox(User $supervisor): Thread
    {
        return $this->inboxOf($supervisor, $supervisor);
    }

    /**
     * Every inbox conversation this system administrator is not a member of
     * yet — started before they held the role — joined in one statement.
     */
    public function joinInbox(User $admin): void
    {
        $missing = Thread::query()
            ->where('inbox', Thread::INBOX_SYSTEM_ADMIN)
            ->whereNotIn('id', ThreadParticipant::query()->where('user_id', $admin->getKey())->select('thread_id'))
            ->pluck('id');

        foreach ($missing as $threadId) {
            $this->join((string) $threadId, (string) $admin->getKey());
        }
    }

    /**
     * Every active system administrator joins this inbox conversation — one
     * whose holder has signed in: an invitation not yet accepted, perhaps to
     * a mistyped address, reads nothing (D-119).
     */
    public function joinAdministrators(Thread $thread): void
    {
        if (! $thread->isInbox()) {
            return;
        }

        User::query()
            ->where('role', UserRole::SystemAdmin->value)
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->pluck('id')
            ->each(fn (mixed $id) => $this->join($thread, (string) $id));
    }

    private function inboxOf(User $supervisor, User $startedBy): Thread
    {
        $thread = $this->firstOrCreate(Thread::inboxKeyFor($supervisor), [
            'cohort_id' => null,
            'type' => ThreadType::Direct->value,
            'inbox' => Thread::INBOX_SYSTEM_ADMIN,
            'title' => null,
            'created_by' => $startedBy->getKey(),
            'is_locked' => false,
        ]);

        $this->join($thread, (string) $supervisor->getKey());
        $this->joinAdministrators($thread);

        return $thread;
    }

    /**
     * The trainer–trainee line the provisioner already opened (D-82), when
     * these two are that pair in some cohort.
     */
    private function provisionedLine(User $one, User $other): ?Thread
    {
        /** @var Thread|null $thread */
        $thread = Thread::query()
            ->where('type', ThreadType::TrainerDm->value)
            ->whereHas('participants', static fn ($q) => $q->where('user_id', $one->getKey()))
            ->whereHas('participants', static fn ($q) => $q->where('user_id', $other->getKey()))
            ->orderByDesc('updated_at')
            ->first();

        return $thread;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function firstOrCreate(string $pairKey, array $attributes): Thread
    {
        $found = Thread::query()->where('pair_key', $pairKey)->first();

        if ($found instanceof Thread) {
            return $found;
        }

        try {
            return DB::transaction(static fn (): Thread => Thread::query()->create($attributes + ['pair_key' => $pairKey]));
        } catch (UniqueConstraintViolationException) {
            // The other side started the same conversation a moment ago. A
            // locking read: inside the caller's transaction a plain one would
            // read the snapshot taken before that row existed (InnoDB).
            return Thread::query()->where('pair_key', $pairKey)->lockForUpdate()->firstOrFail();
        }
    }

    /** Idempotent: a member already there keeps their read marker (D-82). */
    private function join(Thread|string $thread, string $userId): void
    {
        $stamp = Clock::now()->format('Y-m-d H:i:s');

        ThreadParticipant::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'thread_id' => $thread instanceof Thread ? $thread->getKey() : $thread,
            'user_id' => $userId,
            'last_read_at' => null,
            'is_muted' => false,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }
}
