<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketEntryType;
use App\Enums\SupportTicketLevel;
use App\Enums\SupportTicketStatus;
use App\Exceptions\SupportTicketException;
use App\Models\Cohort;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything that happens to a support ticket, and the only writer of one
 * (D-124) — as the owner described it:
 *
 *  · the participant opens it; it reaches the cohort's primary coordinator, or
 *    the general supervisor when the cohort has none (the safety net);
 *  · whoever holds it writes to the participant or keeps a note internal,
 *    and moves it up a level when they cannot solve it — coordinator, general
 *    supervisor, system administrator, never skipping one;
 *  · the answer comes back down the same way, level by level, and the
 *    coordinator alone tells the participant it is resolved;
 *  · a resolved ticket closes itself a day later unless the participant
 *    answers — which reopens it with the coordinator — or closes it first.
 *
 * Every change runs under the ticket's row lock and asks again what the policy
 * already asked: two people acting on the same ticket at once cannot both
 * move it, and the second is told it moved on. Every change is written to the
 * audit trail before it is saved (art. 8), and every instant comes from Clock
 * (BR-07). Notices follow the commit: the ticket stands whether or not one can
 * be written.
 *
 * @see D-124 · BR-07, BR-22, BR-23, BR-33 · CONSTITUTION art. 5, art. 7, art. 8, art. 22
 */
final class TicketWorkflow
{
    public const AUDIT_OPENED = 'support_ticket.opened';

    public const AUDIT_REPLIED = 'support_ticket.replied';

    public const AUDIT_REOPENED = 'support_ticket.reopened';

    public const AUDIT_NOTED = 'support_ticket.noted';

    public const AUDIT_RESOLVED = 'support_ticket.resolved';

    public const AUDIT_ESCALATED = 'support_ticket.escalated';

    public const AUDIT_RETURNED = 'support_ticket.returned';

    public const AUDIT_ASSIGNED = 'support_ticket.assigned';

    public const AUDIT_CLOSED = 'support_ticket.closed';

    public const AUDIT_AUTO_CLOSED = 'support_ticket.auto_closed';

    public const AUDIT_REHOMED = 'support_ticket.rehomed';

    /** How many tickets one scheduled pass handles; the next minute takes the rest. */
    public const BATCH = 200;

    private const DEFAULT_AUTO_CLOSE_HOURS = 24;

    public function __construct(
        private readonly TicketRouting $routing,
        private readonly TicketAttachments $attachments,
        private readonly TicketNumbers $numbers,
        private readonly TicketNotices $notices,
        private readonly AuditLogger $audit,
    ) {}

    /** The owner's "after a day" (config/athar.php, BR-36). */
    public static function autoCloseHours(): int
    {
        $configured = config('athar.support.auto_close_hours');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_AUTO_CLOSE_HOURS;
    }

    /** When a resolved ticket closes itself; null for any other ticket. */
    public static function closesAt(SupportTicket $ticket): ?CarbonImmutable
    {
        if ($ticket->status !== SupportTicketStatus::Resolved || $ticket->resolved_at === null) {
            return null;
        }

        return CarbonImmutable::instance($ticket->resolved_at)->addHours(self::autoCloseHours());
    }

    /**
     * Closed in all but the row: resolved, and its day ran out. From that
     * instant nothing more is written to it, whether or not the scheduled pass
     * has closed it yet — the boundary is the clock's, not the cron's.
     */
    public static function isDue(SupportTicket $ticket, CarbonImmutable $now): bool
    {
        $closesAt = self::closesAt($ticket);

        return $closesAt !== null && $now->greaterThanOrEqualTo($closesAt);
    }

    /** Closed, or due to be. */
    public static function isClosedAt(SupportTicket $ticket, CarbonImmutable $now): bool
    {
        return $ticket->status === SupportTicketStatus::Closed || self::isDue($ticket, $now);
    }

    // ------------------------------------------------------------ participant

    /**
     * @param  list<UploadedFile>  $files
     */
    public function open(
        User $opener,
        ?Cohort $cohort,
        SupportTicketCategory $category,
        string $subject,
        string $body,
        ?string $link,
        array $files = [],
    ): SupportTicket {
        $now = Clock::now();
        [$level, $assigneeId] = $this->routing->entryFor($cohort);

        $ticket = new SupportTicket;
        $ticket->setAttribute($ticket->getKeyName(), (string) Str::uuid());
        $ticket->setAttribute('number', $this->numbers->unused());
        $ticket->setAttribute('opener_id', $opener->getKey());
        $ticket->setAttribute('cohort_id', $cohort?->getKey());
        $ticket->setAttribute('category', $category);
        $ticket->setAttribute('subject', trim($subject));
        $ticket->setAttribute('status', SupportTicketStatus::Open);
        $ticket->setAttribute('level', $level);
        $ticket->setAttribute('assignee_id', $assigneeId);
        $ticket->setAttribute('last_activity_at', $now);
        $this->stamp($ticket, $now);

        $stored = [];

        try {
            DB::transaction(function () use ($ticket, $opener, $body, $link, $files, $now, $level, &$stored): void {
                $this->audit->log(self::AUDIT_OPENED, $ticket, null, $this->snapshot($ticket), $opener);
                $ticket->save();

                $stored = $this->attachments->store($files, $ticket, $opener);

                $this->write($ticket, $opener, SupportTicketEntryType::Opened, $body, false, $now,
                    toLevel: $level, link: $link, files: $stored);
            });
        } catch (\Throwable $failure) {
            $this->attachments->discard($stored, $opener);

            throw $failure;
        }

        $this->notices->opened($ticket);

        return $ticket;
    }

    /**
     * The participant answers — at any time before the ticket closes. An
     * answer to a resolved ticket reopens it with the coordinator, and the
     * day's count stops (D-124).
     *
     * @param  list<UploadedFile>  $files
     */
    public function reply(User $participant, SupportTicket $ticket, string $body, ?string $link, array $files = []): SupportTicket
    {
        $now = Clock::now();
        $stored = [];
        $reopened = false;

        try {
            $locked = DB::transaction(function () use ($participant, $ticket, $body, $link, $files, $now, &$stored, &$reopened): SupportTicket {
                $locked = $this->lock($ticket);
                $this->refuseIfClosed($locked, $now);

                if (! $this->routing->opened($participant, $locked)) {
                    throw SupportTicketException::movedOn();
                }

                $before = $this->snapshot($locked);

                if ($locked->status === SupportTicketStatus::Resolved) {
                    $reopened = true;
                    $locked->setAttribute('status', SupportTicketStatus::InProgress);
                    $locked->setAttribute('resolved_at', null);
                }

                $locked->setAttribute('last_activity_at', $now);
                $this->stamp($locked, $now);

                $this->audit->log($reopened ? self::AUDIT_REOPENED : self::AUDIT_REPLIED, $locked, $before, $this->snapshot($locked), $participant);
                $locked->save();

                if ($reopened) {
                    $this->write($locked, $participant, SupportTicketEntryType::Reopened, null, false, $now);
                }

                $stored = $this->attachments->store($files, $locked, $participant);

                $this->write($locked, $participant, SupportTicketEntryType::Reply, $body, false, $now,
                    link: $link, files: $stored);

                return $locked;
            });
        } catch (\Throwable $failure) {
            $this->attachments->discard($stored, $participant);

            throw $failure;
        }

        $this->notices->replied($locked, $reopened);

        return $locked;
    }

    /** The participant closes their own ticket, whatever stage it is at. */
    public function close(User $participant, SupportTicket $ticket): SupportTicket
    {
        $now = Clock::now();

        $locked = DB::transaction(function () use ($participant, $ticket, $now): SupportTicket {
            $locked = $this->lock($ticket);
            $this->refuseIfClosed($locked, $now);

            if (! $this->routing->opened($participant, $locked)) {
                throw SupportTicketException::movedOn();
            }

            $before = $this->snapshot($locked);

            $locked->setAttribute('status', SupportTicketStatus::Closed);
            $locked->setAttribute('closed_at', $now);
            $locked->setAttribute('closed_by', $participant->getKey());
            $locked->setAttribute('last_activity_at', $now);
            $this->stamp($locked, $now);

            $this->audit->log(self::AUDIT_CLOSED, $locked, $before, $this->snapshot($locked), $participant);
            $locked->save();

            $this->write($locked, $participant, SupportTicketEntryType::Closed, null, false, $now);

            return $locked;
        });

        $this->notices->closed($locked);

        return $locked;
    }

    // ------------------------------------------------------------ the team

    /**
     * A line from the support team. Whoever holds the ticket writes to the
     * participant or keeps it internal; the general supervisor may add an
     * internal note to any ticket (D-124). The coordinator's line to the
     * participant is a `message`, and reaches them on the platform.
     *
     * @param  list<UploadedFile>  $files
     */
    public function note(User $actor, SupportTicket $ticket, string $body, bool $internal, ?string $link, array $files = []): SupportTicketEntry
    {
        $now = Clock::now();
        $stored = [];
        $messaged = false;

        try {
            [$locked, $entry] = DB::transaction(function () use ($actor, $ticket, $body, $internal, $link, $files, $now, &$stored, &$messaged): array {
                $locked = $this->lock($ticket);
                $this->refuseIfClosed($locked, $now);

                $holds = $this->routing->holds($actor, $locked);

                if (! $holds && ! ($this->routing->readsAsStaff($actor, $locked) && $actor->isAdmin())) {
                    throw SupportTicketException::movedOn();
                }

                // Only the one holding it speaks to the participant.
                $internal = $internal || ! $holds;
                $type = $holds && ! $internal && $locked->level === SupportTicketLevel::Coordinator
                    ? SupportTicketEntryType::Message
                    : SupportTicketEntryType::Note;
                $messaged = $type === SupportTicketEntryType::Message;

                $before = $this->snapshot($locked);

                if ($holds && $locked->status === SupportTicketStatus::Open) {
                    $locked->setAttribute('status', SupportTicketStatus::InProgress);
                }

                $locked->setAttribute('last_activity_at', $now);
                $this->stamp($locked, $now);

                $this->audit->log(self::AUDIT_NOTED, $locked, $before, $this->snapshot($locked) + [
                    'entry_type' => $type->value,
                    'is_internal' => $internal,
                ], $actor);
                $locked->save();

                $stored = $this->attachments->store($files, $locked, $actor);

                $entry = $this->write($locked, $actor, $type, $body, $internal, $now,
                    fromLevel: $locked->level, link: $link, files: $stored);

                return [$locked, $entry];
            });
        } catch (\Throwable $failure) {
            $this->attachments->discard($stored, $actor);

            throw $failure;
        }

        if ($messaged) {
            $this->notices->messaged($locked);
        }

        return $entry;
    }

    /** "Resolved" — the coordinator holding it, and only them (D-124). */
    public function resolve(User $actor, SupportTicket $ticket, ?string $body): SupportTicket
    {
        $now = Clock::now();

        $locked = DB::transaction(function () use ($actor, $ticket, $body, $now): SupportTicket {
            $locked = $this->lock($ticket);
            $this->refuseIfClosed($locked, $now);

            if (! $this->routing->holds($actor, $locked)
                || $locked->level !== SupportTicketLevel::Coordinator
                || ! $locked->status->isBeingHandled()) {
                throw SupportTicketException::movedOn();
            }

            $before = $this->snapshot($locked);

            $locked->setAttribute('status', SupportTicketStatus::Resolved);
            $locked->setAttribute('resolved_at', $now);
            $locked->setAttribute('last_activity_at', $now);
            $this->stamp($locked, $now);

            $this->audit->log(self::AUDIT_RESOLVED, $locked, $before, $this->snapshot($locked), $actor);
            $locked->save();

            $this->write($locked, $actor, SupportTicketEntryType::Resolved, $body, false, $now,
                fromLevel: SupportTicketLevel::Coordinator);

            return $locked;
        });

        $this->notices->resolved($locked);

        return $locked;
    }

    /**
     * Up one level — coordinator to general supervisor, general supervisor to
     * system administrator. The move is shown to the participant; the note
     * that goes with it stays with the team.
     */
    public function escalate(User $actor, SupportTicket $ticket, ?string $note): SupportTicket
    {
        $now = Clock::now();

        $locked = DB::transaction(function () use ($actor, $ticket, $note, $now): SupportTicket {
            $locked = $this->lock($ticket);
            $this->refuseIfClosed($locked, $now);

            $from = $locked->level;
            $to = $from->above();

            if ($to === null || ! $this->routing->holds($actor, $locked) || ! $locked->status->isBeingHandled()) {
                throw SupportTicketException::movedOn();
            }

            $before = $this->snapshot($locked);

            $locked->setAttribute('level', $to);
            $locked->setAttribute('status', SupportTicketStatus::InProgress);

            if ($to === SupportTicketLevel::SystemAdmin && $locked->reached_system_admin_at === null) {
                $locked->setAttribute('reached_system_admin_at', $now);
            }

            $locked->setAttribute('last_activity_at', $now);
            $this->stamp($locked, $now);

            $this->audit->log(self::AUDIT_ESCALATED, $locked, $before, $this->snapshot($locked), $actor);
            $locked->save();

            $this->write($locked, $actor, SupportTicketEntryType::Escalated, null, false, $now, fromLevel: $from, toLevel: $to);
            $this->writeHandoverNote($locked, $actor, $note, $now, $from);

            return $locked;
        });

        $this->notices->moved($locked);

        return $locked;
    }

    /**
     * Down one level with the answer — system administrator to general
     * supervisor, general supervisor to a coordinator of the cohort (the
     * primary one unless another is chosen). Never straight to the
     * participant: the coordinator tells them (D-124).
     */
    public function returnDown(User $actor, SupportTicket $ticket, ?string $coordinatorId, ?string $note): SupportTicket
    {
        $now = Clock::now();

        $locked = DB::transaction(function () use ($actor, $ticket, $coordinatorId, $note, $now): SupportTicket {
            $locked = $this->lock($ticket);
            $this->refuseIfClosed($locked, $now);

            $from = $locked->level;
            $to = $from->below();

            if ($to === null || ! $this->routing->holds($actor, $locked) || ! $locked->status->isBeingHandled()) {
                throw SupportTicketException::movedOn();
            }

            $before = $this->snapshot($locked);
            $target = null;

            if ($to === SupportTicketLevel::Coordinator) {
                $target = $this->coordinatorFor($locked, $coordinatorId);
                $locked->setAttribute('assignee_id', $target);
            }

            $locked->setAttribute('level', $to);
            $locked->setAttribute('status', SupportTicketStatus::InProgress);
            $locked->setAttribute('last_activity_at', $now);
            $this->stamp($locked, $now);

            $this->audit->log(self::AUDIT_RETURNED, $locked, $before, $this->snapshot($locked), $actor);
            $locked->save();

            $this->write($locked, $actor, SupportTicketEntryType::Returned, null, false, $now,
                fromLevel: $from, toLevel: $to, targetId: $target);
            $this->writeHandoverNote($locked, $actor, $note, $now, $from);

            return $locked;
        });

        $this->notices->moved($locked);

        return $locked;
    }

    /**
     * The primary coordinator hands a ticket they hold to another coordinator
     * of the cohort (D-124). "With the coordinator" does not change, so the
     * participant is not told — a temporary assumption D-124 records.
     */
    public function assign(User $actor, SupportTicket $ticket, string $coordinatorId, ?string $note): SupportTicket
    {
        $now = Clock::now();

        $locked = DB::transaction(function () use ($actor, $ticket, $coordinatorId, $note, $now): SupportTicket {
            $locked = $this->lock($ticket);
            $this->refuseIfClosed($locked, $now);

            if (! $this->routing->holds($actor, $locked)
                || $locked->level !== SupportTicketLevel::Coordinator
                || ! $locked->status->isBeingHandled()
                || ! $this->routing->isPrimaryCoordinator($actor, $locked)) {
                throw SupportTicketException::movedOn();
            }

            if ($coordinatorId === (string) $actor->getKey()
                || ! in_array($coordinatorId, $this->routing->coordinatorIds($locked), true)) {
                throw SupportTicketException::notACoordinator();
            }

            $before = $this->snapshot($locked);

            $locked->setAttribute('assignee_id', $coordinatorId);
            $locked->setAttribute('status', SupportTicketStatus::InProgress);
            $locked->setAttribute('last_activity_at', $now);
            $this->stamp($locked, $now);

            $this->audit->log(self::AUDIT_ASSIGNED, $locked, $before, $this->snapshot($locked), $actor);
            $locked->save();

            $this->write($locked, $actor, SupportTicketEntryType::Assigned, $note, true, $now,
                fromLevel: SupportTicketLevel::Coordinator, targetId: $coordinatorId);

            return $locked;
        });

        $this->notices->handedOver($locked);

        return $locked;
    }

    // ------------------------------------------------------------ the platform

    /**
     * Close every resolved ticket whose day ran out (D-124). Each is asked
     * again under its lock, so a reply that landed a second earlier wins.
     *
     * @return int how many were closed
     */
    public function closeDue(CarbonImmutable $now): int
    {
        $closed = 0;

        $due = SupportTicket::query()
            ->dueToClose($now, self::autoCloseHours())
            ->orderBy('resolved_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($due as $ticket) {
            $done = DB::transaction(function () use ($ticket, $now): ?SupportTicket {
                $locked = $this->lock($ticket);

                if (! self::isDue($locked, $now)) {
                    return null;
                }

                $before = $this->snapshot($locked);

                $locked->setAttribute('status', SupportTicketStatus::Closed);
                $locked->setAttribute('closed_at', $now);
                $locked->setAttribute('closed_by', null);
                $locked->setAttribute('last_activity_at', $now);
                $this->stamp($locked, $now);

                $this->audit->log(self::AUDIT_AUTO_CLOSED, $locked, $before, $this->snapshot($locked));
                $locked->save();

                $this->write($locked, null, SupportTicketEntryType::AutoClosed, null, false, $now);

                return $locked;
            });

            if ($done !== null) {
                $this->notices->closed($done);
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Tickets whose coordinator can no longer act on them — removed from the
     * cohort, suspended, moved to another role — go back to the primary
     * coordinator, or up to the general supervisor when the cohort has none
     * (the owner's safety net). A resolved ticket waiting on the participant
     * is only moved sideways: it has nothing to escalate.
     *
     * Run for one cohort the moment a coordinator is removed from it, and for
     * every cohort by the scheduled pass, which catches the other doors.
     *
     * @return int how many were moved
     */
    public function rehome(CarbonImmutable $now, ?Cohort $cohort = null, ?User $actor = null): int
    {
        $candidates = SupportTicket::query()
            ->where('level', SupportTicketLevel::Coordinator->value)
            ->where('status', '!=', SupportTicketStatus::Closed->value)
            ->when($cohort !== null, static fn ($query) => $query->where('cohort_id', $cohort?->getKey()))
            ->orderBy('last_activity_at')
            ->limit(self::BATCH)
            ->get();

        $moved = 0;

        foreach ($candidates as $ticket) {
            if ($this->routing->holderCanAct($ticket)) {
                continue;
            }

            $result = DB::transaction(function () use ($ticket, $now, $actor): ?array {
                $locked = $this->lock($ticket);

                if ($locked->level !== SupportTicketLevel::Coordinator
                    || $locked->status === SupportTicketStatus::Closed
                    || $this->routing->holderCanAct($locked)) {
                    return null;
                }

                $primary = $this->routing->primaryIdOf($locked);

                if ($primary === null && ! $locked->status->isBeingHandled()) {
                    return null;
                }

                $before = $this->snapshot($locked);

                if ($primary !== null) {
                    $locked->setAttribute('assignee_id', $primary);
                } else {
                    $locked->setAttribute('level', SupportTicketLevel::Admin);
                }

                $locked->setAttribute('last_activity_at', $now);
                $this->stamp($locked, $now);

                $this->audit->log(self::AUDIT_REHOMED, $locked, $before, $this->snapshot($locked), $actor);
                $locked->save();

                if ($primary !== null) {
                    $this->write($locked, $actor, SupportTicketEntryType::Assigned, null, true, $now, targetId: $primary);

                    return [$locked, 'handed'];
                }

                $this->write($locked, $actor, SupportTicketEntryType::Escalated, null, false, $now,
                    fromLevel: SupportTicketLevel::Coordinator, toLevel: SupportTicketLevel::Admin);

                return [$locked, 'moved'];
            });

            if ($result === null) {
                continue;
            }

            [$locked, $kind] = $result;
            $kind === 'moved' ? $this->notices->moved($locked) : $this->notices->handedOver($locked);
            $moved++;
        }

        return $moved;
    }

    // ------------------------------------------------------------ internals

    private function lock(SupportTicket $ticket): SupportTicket
    {
        $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();

        if (! $locked instanceof SupportTicket) {
            throw SupportTicketException::movedOn();
        }

        return $locked;
    }

    private function refuseIfClosed(SupportTicket $ticket, CarbonImmutable $now): void
    {
        if (self::isClosedAt($ticket, $now)) {
            throw SupportTicketException::closed();
        }
    }

    /**
     * The coordinator a returning ticket reaches: the one chosen, else the
     * primary one, else the only one — and never anyone who is not an active
     * coordinator of the cohort.
     */
    private function coordinatorFor(SupportTicket $ticket, ?string $chosen): string
    {
        $coordinators = $this->routing->coordinatorIds($ticket);

        if ($coordinators === []) {
            throw SupportTicketException::noCoordinator();
        }

        $target = $chosen !== null && $chosen !== ''
            ? $chosen
            : ($this->routing->primaryIdOf($ticket) ?? (count($coordinators) === 1 ? $coordinators[0] : null));

        if ($target === null || ! in_array($target, $coordinators, true)) {
            throw SupportTicketException::notACoordinator();
        }

        return $target;
    }

    /** The note that goes with a move: for the team, never the participant. */
    private function writeHandoverNote(SupportTicket $ticket, User $actor, ?string $note, CarbonImmutable $now, SupportTicketLevel $writtenAt): void
    {
        if ($note !== null && trim($note) !== '') {
            $this->write($ticket, $actor, SupportTicketEntryType::Note, $note, true, $now, fromLevel: $writtenAt);
        }
    }

    /**
     * @param  list<array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, checksum: string, kind: string}>  $files
     */
    private function write(
        SupportTicket $ticket,
        ?User $actor,
        SupportTicketEntryType $type,
        ?string $body,
        bool $internal,
        CarbonImmutable $now,
        ?SupportTicketLevel $fromLevel = null,
        ?SupportTicketLevel $toLevel = null,
        ?string $targetId = null,
        ?string $link = null,
        array $files = [],
    ): SupportTicketEntry {
        $text = $body === null ? '' : trim($body);
        $url = $link === null ? '' : trim($link);

        $entry = new SupportTicketEntry;
        $entry->setAttribute($entry->getKeyName(), (string) Str::uuid());
        $entry->setAttribute('support_ticket_id', $ticket->getKey());
        $entry->setAttribute('position', 1 + (int) SupportTicketEntry::query()->where('support_ticket_id', $ticket->getKey())->max('position'));
        $entry->setAttribute('actor_id', $actor?->getKey());
        $entry->setAttribute('type', $type);
        $entry->setAttribute('body', $text === '' ? null : $text);
        $entry->setAttribute('is_internal', $internal);
        $entry->setAttribute('from_level', $fromLevel);
        $entry->setAttribute('to_level', $toLevel);
        $entry->setAttribute('target_id', $targetId);
        $entry->setAttribute('link_url', $url === '' ? null : $url);
        $this->stamp($entry, $now);
        $entry->save();

        foreach ($files as $file) {
            $attachment = new SupportTicketAttachment;
            $attachment->setAttribute($attachment->getKeyName(), (string) Str::uuid());
            $attachment->setAttribute('support_ticket_entry_id', $entry->getKey());
            $attachment->setAttribute('disk', $file['disk']);
            $attachment->setAttribute('path', $file['path']);
            $attachment->setAttribute('original_name', $file['original_name']);
            $attachment->setAttribute('mime_type', $file['mime_type']);
            $attachment->setAttribute('size_bytes', $file['size_bytes']);
            $attachment->setAttribute('checksum', $file['checksum']);
            $attachment->setAttribute('kind', $file['kind']);
            $this->stamp($attachment, $now);
            $attachment->save();
        }

        return $entry;
    }

    /** Timestamps from Clock, not from the framework's clock (BR-07). */
    private function stamp(Model $model, CarbonImmutable $now): void
    {
        if (! $model->exists) {
            $model->setCreatedAt($now);
        }

        $model->setUpdatedAt($now);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(SupportTicket $ticket): array
    {
        return $this->audit->snapshot($ticket, [
            'id',
            'number',
            'opener_id',
            'cohort_id',
            'status',
            'level',
            'assignee_id',
            'reached_system_admin_at',
            'resolved_at',
            'closed_at',
            'closed_by',
        ]);
    }
}
