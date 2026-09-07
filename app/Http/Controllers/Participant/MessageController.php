<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\ThreadType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\ReportMessageRequest;
use App\Http\Requests\Participant\SendMessageRequest;
use App\Http\Requests\Participant\UpdateMessageRequest;
use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Presenters\Participant\ActiveThreadPresenter;
use App\Presenters\Participant\MessagePresenter;
use App\Presenters\Participant\ThreadPresenter;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Internal messaging (PRD §9.13).
 *
 * A thread is reachable only through the `thread_participants` row that puts
 * this account in it; there is no query here that starts from a thread id
 * alone, so changing the id in the URL ends in 403 (BR-22).
 *
 * Announcements are read-only for participants. The composer is not rendered
 * for them and the send endpoint refuses them as well — the policy decides, in
 * one place, for both (Art. 5).
 *
 * While an account preview is running, no read receipt is written (BR-34).
 *
 * @see BR-22, BR-33, BR-34 · PRD §9.13 · CONSTITUTION Art. 5, Art. 22
 */
final class MessageController extends Controller
{
    /** How many messages one thread view loads at a time. */
    private const MESSAGE_LIMIT = 50;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'messages';

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Thread::class);

        $threads = Thread::query()
            ->with(['latestMessage.sender.profile', 'cohort'])
            ->whereIn('id', ThreadParticipant::query()
                ->where('user_id', $user->getKey())
                ->select('thread_id'))
            ->orderByDesc('updated_at')
            ->get();

        $active = $this->activeThread($request, $threads);
        $isImpersonating = ImpersonationContext::isActive();
        $now = Clock::now();

        // BR-22 — the unread tally is read from this account's own participation
        // rows and from no one else's.
        $participation = ThreadParticipant::query()
            ->where('user_id', $user->getKey())
            ->whereIn('thread_id', $threads->modelKeys())
            ->get()
            ->keyBy(static fn (ThreadParticipant $row): string => (string) $row->getAttribute('thread_id'));

        return view('participant.messages', [
            'threads' => $threads->map(
                fn (Thread $thread): ThreadPresenter => ThreadPresenter::from(
                    $thread,
                    $this->unreadCount($thread, $participation->get((string) $thread->getKey())),
                )
            ),
            'activeThread' => $active === null
                ? null
                : ActiveThreadPresenter::from(
                    $active,
                    // Asked once, not once per message: the read stamp is a
                    // property of the thread, not of a line in it (art. 19).
                    $this->presentMessages($active, $user, $now),
                    $isImpersonating,
                ),
            'isImpersonating' => $isImpersonating,
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::of($threads->isEmpty()),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, MessagePresenter>
     */
    private function presentMessages(Thread $thread, User $user, \Carbon\CarbonImmutable $now)
    {
        $readAt = $this->otherPartyReadAt($thread, $user);

        return $this->messages($thread)->map(
            static fn (Message $message): MessagePresenter => MessagePresenter::from(
                $message,
                $user,
                $now,
                $readAt,
            )
        );
    }

    /**
     * How many messages in this thread arrived after this account last read it.
     * A thread it has never opened counts every message in it.
     *
     * One COUNT per thread. PRD §9.13 gives a participant three threads — the
     * trainer DM, the cohort group and the announcements channel — so the loop
     * is bounded by the product, not by the data.
     */
    private function unreadCount(Thread $thread, ?ThreadParticipant $participation): int
    {
        $lastReadAt = $participation?->getAttribute('last_read_at');

        $query = Message::query()->where('thread_id', $thread->getKey());

        if ($lastReadAt !== null) {
            $query->where('sent_at', '>', $lastReadAt);
        }

        return $query->count();
    }

    /**
     * When the other side of a trainer DM last read it — the only thing the
     * read marker may be built on. Group and announcement threads have
     * no single other side, so they get none.
     */
    private function otherPartyReadAt(Thread $thread, User $user): ?\DateTimeInterface
    {
        if (ThreadPresenter::typeOf($thread) !== ThreadType::TrainerDm) {
            return null;
        }

        $readAt = ThreadParticipant::query()
            ->where('thread_id', $thread->getKey())
            ->where('user_id', '!=', $user->getKey())
            ->orderByDesc('last_read_at')
            ->value('last_read_at');

        return $readAt instanceof \DateTimeInterface ? $readAt : null;
    }

    /**
     * Poll for new messages in one thread. Same authorisation as the page: the
     * policy is asked before a single row is read.
     */
    public function poll(Thread $thread): JsonResponse
    {
        $this->authorize('view', $thread);

        return new JsonResponse([
            'messages' => $this->messages($thread)
                ->map(static fn (Message $message): array => [
                    'id' => (string) $message->getKey(),
                    'body' => (string) $message->getAttribute('body'),
                    'sent_at' => Clock::toUtc($message->getAttribute('sent_at'))->toIso8601String(),
                    'sender_id' => (string) $message->getAttribute('sender_id'),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(SendMessageRequest $request, Thread $thread): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        Message::query()->create([
            'thread_id' => $thread->getKey(),
            'sender_id' => $user->getKey(),
            'body' => (string) $request->validated('body'),
            'attachments' => [],
            'sent_at' => Clock::now(),
        ]);

        $thread->touch();

        return back()->with('status', __('messages.sent'));
    }

    /** Editing one's own message keeps the original send time (PRD §9.13). */
    public function update(UpdateMessageRequest $request, Message $message): RedirectResponse
    {
        $message->forceFill([
            'body' => (string) $request->validated('body'),
            'edited_at' => Clock::now(),
        ])->save();

        return back()->with('status', __('messages.edited'));
    }

    /** Reporting a message writes to the trail; it never deletes anything. */
    public function report(ReportMessageRequest $request, Message $message): RedirectResponse
    {
        $this->audit->log(
            action: 'message.reported',
            entity: $message,
            before: null,
            after: ['reason' => (string) $request->validated('reason')],
        );

        return back()->with('status', __('messages.reported'));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Thread>  $threads
     */
    private function activeThread(Request $request, $threads): ?Thread
    {
        $requested = $request->query('thread');

        if (is_string($requested) && $requested !== '') {
            $match = $threads->first(
                static fn (Thread $thread): bool => (string) $thread->getKey() === $requested
            );

            // A thread the account is not in is simply not in this collection,
            // so an unknown id falls back rather than leaking its existence.
            if ($match instanceof Thread) {
                return $match;
            }
        }

        $first = $threads->first();

        return $first instanceof Thread ? $first : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Message>
     */
    private function messages(Thread $thread)
    {
        return Message::query()
            ->with('sender.profile')
            ->where('thread_id', $thread->getKey())
            ->orderByDesc('sent_at')
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->reverse()
            ->values();
    }
}
