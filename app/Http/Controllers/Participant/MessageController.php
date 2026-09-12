<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\ThreadType;
use App\Events\MessageReceived;
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
use Illuminate\Support\Str;

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
            ->with(['latestMessage.sender.profile', 'cohort', 'users.profile'])
            ->whereIn('id', ThreadParticipant::query()
                ->where('user_id', $user->getKey())
                ->select('thread_id'))
            ->orderByDesc('updated_at')
            ->get();

        $active = $this->activeThread($request, $threads);
        $isImpersonating = ImpersonationContext::isActive();
        $now = Clock::now();

        // Opening a conversation is reading it. Nothing wrote this marker, so
        // every unread count counted everything forever and the trainer's
        // "read" receipt never appeared (D-75). Never during a preview: the
        // policy's markRead refuses writes there, and a preview is not the
        // person reading (BR-33). Written before the counts below, so the
        // thread on screen does not show itself as unread.
        if ($active !== null && $user->can('markRead', $active)) {
            ThreadParticipant::query()
                ->where('thread_id', $active->getKey())
                ->where('user_id', $user->getKey())
                ->update(['last_read_at' => $now]);
        }

        // One grouped count for every thread, not one query per thread: a
        // trainer has a direct line to every participant (D-82).
        $unread = Message::query()
            ->unreadBy($user)
            ->whereIn('messages.thread_id', $threads->modelKeys())
            ->reorder()
            ->toBase()
            ->select('messages.thread_id')
            ->selectRaw('count(*) as unread')
            ->groupBy('messages.thread_id')
            ->pluck('unread', 'thread_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return view('participant.messages', [
            'threads' => $threads->map(
                fn (Thread $thread): ThreadPresenter => ThreadPresenter::from(
                    $thread,
                    // BR-22 — this account's own unread count, and no one else's.
                    $unread[(string) $thread->getKey()] ?? 0,
                    $user,
                ),
            ),
            'activeThread' => $active === null
                ? null
                : ActiveThreadPresenter::from(
                    $active,
                    // Asked once, not once per message: the read stamp is a
                    // property of the thread, not of a line in it (art. 19).
                    $this->presentMessages($active, $user, $now),
                    $isImpersonating,
                    $user,
                ),
            'isImpersonating' => $isImpersonating,
            'pollSeconds' => max(0, (int) config('athar.messages.poll_seconds')),
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
            ),
        );
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
    /**
     * The live half of a conversation (PRD §9.13.2): the same message list the
     * page renders, rendered again, plus the id of the newest message so the
     * client swaps the list only when something changed.
     *
     * It returned raw rows before, and nothing called it — the client component
     * was never written (D-67). Raw rows would also have made the browser
     * re-implement authorship, the read stamp and the edit window; returning
     * the server's own markup keeps those decisions here.
     *
     * Read-only by design: it does not mark anything read, so a tab left open
     * does not tell a trainer a message was seen when nobody looked at it.
     */
    public function poll(Request $request, Thread $thread): JsonResponse
    {
        $this->authorize('view', $thread);

        /** @var User $user */
        $user = $request->user();

        $active = ActiveThreadPresenter::from(
            $thread->loadMissing('cohort'),
            $this->presentMessages($thread, $user, Clock::now()),
            ImpersonationContext::isActive(),
        );

        return new JsonResponse([
            'latest' => (string) $active->get('latestMessageId'),
            'html' => view('participant.partials.message-stream', ['activeThread' => $active])->render(),
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

        // Everyone on the thread except the person who just wrote. An excerpt
        // only: reproducing a conversation by e-mail defeats the point of
        // holding it inside the platform, where it is scoped, reportable and
        // audited.
        //
        // `counterpartFor()` and `displayName()` were assumed and do not exist;
        // the thread's own `users` relation and the profile's `full_name_ar`
        // are what the project actually has.
        $sender = $request->user();
        $senderName = (string) ($sender?->profile?->getAttribute('full_name_ar') ?? '');

        foreach ($thread->users as $participant) {
            if ($sender !== null && $participant->is($sender)) {
                continue;
            }

            MessageReceived::dispatch(
                $participant,
                $senderName,
                (string) $thread->getAttribute('title'),
                Str::limit((string) $request->validated('body'), 120),
            );
        }

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
                static fn (Thread $thread): bool => (string) $thread->getKey() === $requested,
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
