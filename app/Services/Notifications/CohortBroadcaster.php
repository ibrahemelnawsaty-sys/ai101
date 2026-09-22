<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\AssignmentStatus;
use App\Enums\BroadcastKind;
use App\Enums\SessionStatus;
use App\Mail\NoticeLetter;
use App\Models\Assignment;
use App\Models\Broadcast;
use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Session;
use App\Models\Submission;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\CohortAudience;
use App\Services\Time\Clock;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * What an administrator sends to a cohort by hand (D-87).
 *
 * THREE SENDS
 *   · a MESSAGE — their own subject and words, to every active trainee, by
 *     e-mail and, if they choose, as a platform notice;
 *   · a SESSIONS reminder — every upcoming session of the cohort, in ONE
 *     letter per trainee rather than one per session;
 *   · an ASSIGNMENTS reminder — each trainee's OWN unsubmitted work that is
 *     still open, in one letter; a trainee with nothing outstanding is sent
 *     nothing.
 *
 * THE AUTOMATIC REMINDERS ARE UNTOUCHED. ScheduledNotices claims its sends in
 * `scheduled_notices`; nothing here reads or writes that table, so a manual
 * reminder never cancels, and is never cancelled by, an automatic one.
 *
 * EVERY SEND RESPECTS WHAT THE TRAINEE CHOSE. The letters go through
 * CohortAudience::reachable() with a notification type — `admin_broadcast`,
 * `session_reminder`, `assignment_due_reminder` — so a trainee who switched
 * that type off by e-mail is not written to, and InAppNotifier applies the
 * same rule to the platform notice.
 *
 * ONE SEND PER PRESS. The cohort row is locked while the previous send is
 * read and the new one recorded, so two presses — a double click, two
 * administrators — cannot both pass. A reminder of the same kind inside the
 * cooldown is refused; a message is refused only when it is the SAME message
 * again, because a correction sent a minute later is legitimate.
 *
 * @see PRD §9.16, §9.16.1, §9.18 · BR-22, BR-23, BR-33 · D-83, D-87
 */
final class CohortBroadcaster
{
    /** A digest lists at most this many rows; the rest are counted. */
    public const MAX_ROWS = 30;

    public function __construct(
        private readonly CohortAudience $audience,
        private readonly InAppNotifier $notifier,
        private readonly AttendanceWindow $window,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Who a message to this cohort reaches, for the send screen to say so
     * before anything is sent.
     *
     * @return array{participants: int, byEmail: int}
     */
    public function reach(string $cohortId): array
    {
        return [
            'participants' => $this->audience->participants($cohortId)->count(),
            'byEmail' => $this->audience->reachable($cohortId, 'admin_broadcast')->count(),
        ];
    }

    /**
     * @throws BroadcastRefused
     */
    public function message(User $admin, Cohort $cohort, string $subject, string $body, bool $inApp): Broadcast
    {
        $now = Clock::now();
        $cohortId = (string) $cohort->getKey();

        return $this->recorded($admin, $cohort, BroadcastKind::Message, $now,
            guard: function () use ($cohortId, $subject, $body, $now): void {
                // Compared here, byte for byte — not in SQL. MySQL's
                // utf8mb4_unicode_ci treats a hamza-less alif and a hamza on
                // alif, or two cases of a Latin word, as equal, so the
                // correction D-87 promises to let through would have been
                // refused as the same message.
                $repeat = Broadcast::query()
                    ->where('cohort_id', $cohortId)
                    ->ofKind(BroadcastKind::Message)
                    ->where('created_at', '>', $now->subMinutes($this->cooldown()))
                    ->get(['subject', 'body'])
                    ->contains(static fn (Broadcast $sent): bool => $sent->getAttribute('subject') === $subject
                        && $sent->getAttribute('body') === $body);

                if ($repeat) {
                    throw new BroadcastRefused('admin.broadcasts.errors.same_message');
                }
            },
            send: function () use ($cohort, $cohortId, $subject, $body, $inApp, $now): array {
                $participants = $this->audience->participants($cohortId);
                $reached = [];

                if ($inApp) {
                    $reached = $this->notifier->deliver(
                        $participants->map(static fn (User $user): string => (string) $user->getKey()),
                        'admin_broadcast',
                        Str::limit($subject, 190),
                        Str::limit(trim((string) preg_replace('/\s+/u', ' ', $body)), 180),
                        route('notifications'),
                        $now,
                    );
                }

                $paragraphs = NoticeLetter::paragraphsOf($body);
                $emails = 0;

                foreach ($this->audience->reachable($cohortId, 'admin_broadcast') as $user) {
                    $mailed = $this->mail($user, new NoticeLetter(
                        subjectLine: $subject,
                        heading: $subject,
                        paragraphs: $paragraphs,
                        ctaLabel: (string) __('emails.broadcast.cta'),
                        ctaUrl: route('dashboard'),
                        eyebrow: (string) $cohort->getAttribute('name'),
                    ), BroadcastKind::Message);

                    $emails += $mailed;

                    if ($mailed === 1) {
                        $reached[] = (string) $user->getKey();
                    }
                }

                return ['recipients' => count(array_unique($reached)), 'emails' => $emails,
                    'subject' => $subject, 'body' => $body, 'in_app' => $inApp];
            },
        );
    }

    /**
     * Every upcoming, not-cancelled session of the cohort, in one letter.
     *
     * @throws BroadcastRefused
     */
    public function sessions(User $admin, Cohort $cohort): Broadcast
    {
        $now = Clock::now();
        $cohortId = (string) $cohort->getKey();

        return $this->recorded($admin, $cohort, BroadcastKind::Sessions, $now,
            guard: fn () => $this->refuseInsideCooldown($cohortId, BroadcastKind::Sessions, $now),
            send: function () use ($cohortId, $now): array {
                $upcoming = $this->upcomingSessions($cohortId, $now);

                if ($upcoming->isEmpty()) {
                    throw new BroadcastRefused('admin.broadcasts.errors.no_sessions');
                }

                $first = $upcoming->first();
                $participants = $this->audience->participants($cohortId);

                $reached = $this->notifier->deliver(
                    $participants->map(static fn (User $user): string => (string) $user->getKey()),
                    'session_reminder',
                    trans_choice('notifications.digest.sessions_title', $upcoming->count(), ['count' => $upcoming->count()]),
                    (string) __('notifications.digest.sessions_body', [
                        'session' => $first['title'],
                        'datetime' => $first['when'],
                    ]),
                    route('schedule'),
                    $now,
                );

                $rows = $this->capped(array_values($upcoming->map(static fn (array $s): array => [$s['title'], $s['when']])->all()));
                $emails = 0;

                foreach ($this->audience->reachable($cohortId, 'session_reminder') as $user) {
                    $mailed = $this->mail($user, new NoticeLetter(
                        subjectLine: (string) __('emails.sessions_digest.subject', ['program' => (string) config('athar.program_name')]),
                        heading: (string) __('emails.sessions_digest.heading'),
                        paragraphs: [(string) __('emails.sessions_digest.body')],
                        rows: $rows,
                        ctaLabel: (string) __('emails.sessions_digest.cta'),
                        ctaUrl: route('schedule'),
                        preheader: (string) __('emails.sessions_digest.preheader'),
                    ), BroadcastKind::Sessions);

                    $emails += $mailed;

                    if ($mailed === 1) {
                        $reached[] = (string) $user->getKey();
                    }
                }

                return ['recipients' => count(array_unique($reached)), 'emails' => $emails];
            },
        );
    }

    /**
     * Each trainee's own open, unsubmitted work — assignments and the final
     * project — in one letter. Nobody with nothing outstanding hears a thing.
     *
     * @throws BroadcastRefused
     */
    public function assignments(User $admin, Cohort $cohort): Broadcast
    {
        $now = Clock::now();
        $cohortId = (string) $cohort->getKey();

        return $this->recorded($admin, $cohort, BroadcastKind::Assignments, $now,
            guard: fn () => $this->refuseInsideCooldown($cohortId, BroadcastKind::Assignments, $now),
            send: function () use ($cohortId, $now): array {
                $participants = $this->audience->participants($cohortId);
                $pending = $this->pendingWork($cohortId, $participants, $now);

                if ($pending === []) {
                    throw new BroadcastRefused('admin.broadcasts.errors.no_pending');
                }

                $reached = [];

                foreach ($pending as $userId => $items) {
                    $nearest = $items[0];

                    $reached = [...$reached, ...$this->notifier->deliver(
                        [$userId],
                        'assignment_due_reminder',
                        trans_choice('notifications.digest.assignments_title', count($items), ['count' => count($items)]),
                        (string) __('notifications.digest.assignments_body', [
                            'assignment' => $nearest['title'],
                            'when' => $nearest['bell'],
                        ]),
                        route('assignments.index'),
                        $now,
                    )];
                }

                $emails = 0;

                foreach ($this->audience->reachable($cohortId, 'assignment_due_reminder') as $user) {
                    $items = $pending[(string) $user->getKey()] ?? [];

                    if ($items === []) {
                        continue;
                    }

                    $mailed = $this->mail($user, new NoticeLetter(
                        subjectLine: (string) __('emails.assignments_digest.subject', ['program' => (string) config('athar.program_name')]),
                        heading: (string) __('emails.assignments_digest.heading'),
                        paragraphs: [(string) __('emails.assignments_digest.body')],
                        rows: $this->capped(array_map(static fn (array $i): array => [$i['title'], $i['row']], $items)),
                        ctaLabel: (string) __('emails.assignments_digest.cta'),
                        ctaUrl: route('assignments.index'),
                        preheader: (string) __('emails.assignments_digest.preheader'),
                    ), BroadcastKind::Assignments);

                    $emails += $mailed;

                    if ($mailed === 1) {
                        $reached[] = (string) $user->getKey();
                    }
                }

                return ['recipients' => count(array_unique($reached)), 'emails' => $emails];
            },
        );
    }

    // -------------------------------------------------------------- plumbing

    /**
     * Lock the cohort, run the guard, send, and record the send — all or
     * nothing. A refusal thrown by the guard or by the send leaves no row.
     *
     * @param  callable(): void  $guard
     * @param  callable(): array{recipients: int, emails: int, subject?: string, body?: string, in_app?: bool}  $send
     *
     * @throws BroadcastRefused
     */
    private function recorded(User $admin, Cohort $cohort, BroadcastKind $kind, CarbonImmutable $now, callable $guard, callable $send): Broadcast
    {
        return DB::transaction(function () use ($admin, $cohort, $kind, $now, $guard, $send): Broadcast {
            // Serialise sends to one cohort: the guard's read and the insert
            // below happen under the same lock (D-69's pattern).
            Cohort::query()->whereKey($cohort->getKey())->lockForUpdate()->first();

            $guard();
            $result = $send();

            /** @var Broadcast $broadcast */
            $broadcast = Broadcast::query()->create([
                'cohort_id' => $cohort->getKey(),
                'kind' => $kind->value,
                'subject' => $result['subject'] ?? null,
                'body' => $result['body'] ?? null,
                'in_app' => $result['in_app'] ?? true,
                'sent_by' => $admin->getKey(),
                'recipients' => $result['recipients'],
                'emails' => $result['emails'],
                'created_at' => $now,
            ]);

            // What was sent and to how many — never the addresses (art. 12).
            $this->audit->log('broadcast.sent', $broadcast, null, [
                'kind' => $kind->value,
                'cohort_id' => (string) $cohort->getKey(),
                'recipients' => $result['recipients'],
                'emails' => $result['emails'],
            ], $admin);

            return $broadcast;
        });
    }

    /**
     * @throws BroadcastRefused
     */
    private function refuseInsideCooldown(string $cohortId, BroadcastKind $kind, CarbonImmutable $now): void
    {
        $recent = Broadcast::query()
            ->where('cohort_id', $cohortId)
            ->ofKind($kind)
            ->where('created_at', '>', $now->subMinutes($this->cooldown()))
            ->exists();

        if ($recent) {
            throw new BroadcastRefused('admin.broadcasts.errors.too_soon', [
                'period' => trans_choice('admin.broadcasts.minutes', $this->cooldown(), ['count' => $this->cooldown()]),
            ]);
        }
    }

    /** BR-36: the window is configuration, never a literal here. */
    private function cooldown(): int
    {
        return max(1, (int) config('athar.broadcasts.cooldown_minutes', 10));
    }

    /**
     * @return Collection<int, array{title: string, when: string}>
     */
    private function upcomingSessions(string $cohortId, CarbonImmutable $now): Collection
    {
        return Session::query()
            ->where('cohort_id', $cohortId)
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereDate('date', '>=', Clock::toRiyadh($now)->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (Session $session): array => ['session' => $session, 'start' => $this->window->startsAt($session)])
            ->filter(static fn (array $s): bool => $s['start']->greaterThan($now))
            ->sortBy(static fn (array $s): int => $s['start']->getTimestamp())
            ->values()
            ->map(static fn (array $s): array => [
                'title' => Present::text($s['session']->getAttribute('topic')) ?? (string) $s['session']->getAttribute('title'),
                'when' => Dates::dateTime($s['start']),
            ]);
    }

    /**
     * Each participant's open work that has no hand-in yet, nearest deadline
     * first: the cohort's published assignments still open, and the final
     * project once unlocked. Keyed by user id; nobody with nothing pending.
     *
     * @param  Collection<int, User>  $participants
     * @return array<string, list<array{title: string, row: string, bell: string, at: int}>>
     */
    private function pendingWork(string $cohortId, Collection $participants, CarbonImmutable $now): array
    {
        $ids = $participants->map(static fn (User $user): string => (string) $user->getKey())->all();

        if ($ids === []) {
            return [];
        }

        $open = [];

        // Open means what Assignment::scopeOpenFor means everywhere else — the
        // rail's badge, the submit endpoint: the deadline has not passed (the
        // deadline itself still counts), OR a late hand-in is still accepted.
        // `due_at > now` skipped both, and told the administrator that
        // everyone had handed in while trainees could still submit (D-87).
        $assignments = Assignment::query()
            ->where('cohort_id', $cohortId)
            ->where('status', AssignmentStatus::Published->value)
            ->where(static fn ($open) => $open->where('due_at', '>=', $now)->orWhere('allow_late', true))
            ->orderBy('due_at')
            ->get(['id', 'title', 'due_at', 'allow_late']);

        $handedIn = Submission::query()
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->whereIn('user_id', $ids)
            ->distinct()
            ->get(['assignment_id', 'user_id'])
            ->map(static fn (Submission $s): string => $s->getAttribute('user_id').'|'.$s->getAttribute('assignment_id'))
            ->flip();

        foreach ($assignments as $assignment) {
            $due = $assignment->getAttribute('due_at');

            if (! $due instanceof \DateTimeInterface) {
                continue;
            }

            foreach ($ids as $userId) {
                if ($handedIn->has($userId.'|'.$assignment->getKey())) {
                    continue;
                }

                $open[$userId][] = $this->item((string) $assignment->getAttribute('title'), $due, $now);
            }
        }

        $project = FinalProject::query()
            ->where('cohort_id', $cohortId)
            ->where('is_unlocked', true)
            ->where(static fn ($open) => $open->whereNull('due_at')->orWhere('due_at', '>=', $now))
            ->first();

        if ($project instanceof FinalProject) {
            $delivered = ProjectSubmission::query()
                ->where('final_project_id', $project->getKey())
                ->whereIn('user_id', $ids)
                ->pluck('user_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip();

            foreach ($ids as $userId) {
                if (! $delivered->has($userId)) {
                    $due = $project->getAttribute('due_at');
                    $open[$userId][] = $this->item((string) $project->getAttribute('title'), $due instanceof \DateTimeInterface ? $due : null, $now);
                }
            }
        }

        foreach ($open as $userId => $items) {
            usort($items, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $open[$userId] = $items;
        }

        return $open;
    }

    /**
     * One piece of open work, worded for the letter's row and the bell.
     *
     * Three cases: a deadline ahead («due … · in …»), a deadline passed with
     * a late hand-in still accepted, and no deadline at all. Sorted by the
     * deadline, so work already late comes first and work with none comes last.
     *
     * @return array{title: string, row: string, bell: string, at: int}
     */
    private function item(string $title, ?\DateTimeInterface $due, CarbonImmutable $now): array
    {
        if ($due === null) {
            return [
                'title' => $title,
                'row' => (string) __('emails.assignments_digest.no_deadline'),
                'bell' => (string) __('notifications.digest.no_deadline'),
                'at' => PHP_INT_MAX,
            ];
        }

        if ($due->getTimestamp() < $now->getTimestamp()) {
            return [
                'title' => $title,
                'row' => (string) __('emails.assignments_digest.late', ['date' => Dates::dateTime($due)]),
                'bell' => (string) __('notifications.digest.late_open'),
                'at' => $due->getTimestamp(),
            ];
        }

        $countdown = Present::durationLabel($due, $now);

        return [
            'title' => $title,
            'row' => (string) __('emails.assignments_digest.due', ['date' => Dates::dateTime($due), 'countdown' => $countdown]),
            'bell' => (string) __('notifications.digest.closes_in', ['countdown' => $countdown]),
            'at' => $due->getTimestamp(),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     * @return list<array{0: string, 1: string}>
     */
    private function capped(array $rows): array
    {
        if (count($rows) <= self::MAX_ROWS) {
            return $rows;
        }

        $more = count($rows) - self::MAX_ROWS;
        $kept = array_slice($rows, 0, self::MAX_ROWS);
        $kept[] = [(string) trans_choice('emails.common.and_more', $more, ['count' => $more]), ''];

        return $kept;
    }

    /**
     * Queue one letter. A letter that fails to queue changes nothing about the
     * send; it is logged without the address or the message (art. 12).
     *
     * @return int 1 when queued, 0 when it failed
     */
    private function mail(User $user, NoticeLetter $letter, BroadcastKind $kind): int
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send($letter);

            return 1;
        } catch (\Throwable $exception) {
            Log::warning('mail.broadcast_failed', [
                'kind' => $kind->value,
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);

            return 0;
        }
    }
}
