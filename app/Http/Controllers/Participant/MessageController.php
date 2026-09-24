<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\ReportMessageRequest;
use App\Http\Requests\Participant\SendMessageRequest;
use App\Http\Requests\Participant\StartConversationRequest;
use App\Http\Requests\Participant\UpdateMessageRequest;
use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Presenters\Participant\ActiveThreadPresenter;
use App\Presenters\Participant\MessagePresenter;
use App\Presenters\Participant\RecipientPresenter;
use App\Presenters\Participant\ThreadPresenter;
use App\Services\Audit\AuditLogger;
use App\Services\Messages\ConversationRules;
use App\Services\Messages\ConversationStarter;
use App\Services\Notifications\CohortNotices;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 * D-118 added the conversations people start themselves: `create` lists whom
 * this account may write to (ConversationRules), `start` opens the one
 * conversation with that person — or with the system administrators' shared
 * inbox — and posts its first message. Which conversations the list shows is
 * Thread::scopeVisibleTo, the same rule ThreadPolicy::view applies to one.
 *
 * @see BR-22, BR-33, BR-34 · PRD §9.13 · CONSTITUTION Art. 5, Art. 22 · D-118
 */
final class MessageController extends Controller
{
    /** How many messages one thread view loads at a time. */
    private const MESSAGE_LIMIT = 50;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'messages';

    /** People per page in the recipient picker (art. 19: paginate past 50). */
    private const RECIPIENTS_PER_PAGE = 20;

    /**
     * Conversations per page in the list. A general supervisor may now talk
     * to anyone (D-118), so the list is paginated like every list past 50.
     */
    private const THREADS_PER_PAGE = 50;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CohortNotices $notices,
        private readonly ConversationRules $rules,
        private readonly ConversationStarter $starter,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Thread::class);

        $isImpersonating = ImpersonationContext::isActive();

        // A system administrator who arrived after an inbox conversation began
        // joins it here, so it has their read marker (D-118). Never from a
        // preview — and a system administrator is never the one previewed.
        if ($user->isSystemAdmin() && ! $isImpersonating) {
            $this->starter->joinInbox($user);
        }

        $pages = Thread::query()
            ->visibleTo($user)
            ->with(['latestMessage.sender.profile', 'cohort', 'users.profile'])
            ->orderByDesc('updated_at')
            ->paginate(self::THREADS_PER_PAGE)
            ->withQueryString();

        $threads = $pages->getCollection();

        $active = $this->activeThread($request, $threads, $user);
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
            'threadPages' => $pages,
            'isImpersonating' => $isImpersonating,
            // D-118 — the "new conversation" door; its page and its start
            // endpoint refuse a preview again on their own.
            'canStart' => ! $isImpersonating,
            'pollSeconds' => max(0, (int) config('athar.messages.poll_seconds')),
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::of($threads->isEmpty()),
        ]);
    }

    /**
     * The people this account may start a conversation with (D-118): exactly
     * ConversationRules::recipients, searched by name or address, a page at a
     * time — the start endpoint asks the same query, so nothing offered here
     * is refused there, and nothing refused there is offered here.
     */
    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Thread::class);

        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';

        $recipients = $this->rules->recipients($user)
            ->with('profile')
            ->when($search !== '', static function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(static fn ($match) => $match
                    ->where('email', 'like', $term)
                    ->orWhereHas('profile', static fn ($profile) => $profile
                        ->where('full_name_ar', 'like', $term)
                        ->orWhere('full_name_en', 'like', $term)));
            })
            ->orderBy('role')
            ->orderBy('email')
            ->paginate(self::RECIPIENTS_PER_PAGE)
            ->withQueryString()
            ->through(static fn (User $person): RecipientPresenter => RecipientPresenter::from($person));

        $options = [];

        foreach ($recipients->items() as $person) {
            $options[] = $person->option();
        }

        // The shared inbox heads the first page of an unfiltered list: it is
        // one choice, not a person to search for.
        if ($user->can('startInbox', Thread::class) && $recipients->currentPage() === 1 && $search === '') {
            array_unshift($options, [
                'value' => StartConversationRequest::INBOX,
                'label' => (string) __('messages.inbox.title'),
                'description' => (string) __('messages.inbox.option_hint'),
            ]);
        }

        return view('participant.messages-new', [
            'recipients' => $recipients,
            'options' => $options,
            'search' => $search,
            'errorState' => null,
        ]);
    }

    /**
     * Open the one conversation with the chosen person — or the shared inbox
     * — and post its first message (D-118). An existing conversation is
     * reused, never duplicated; the message lands in it.
     */
    public function start(StartConversationRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $to = $request->recipientUser();

        // The inbox only when it was asked for — never as a fallback for a
        // recipient that is not there (D-118 review): that would hand a
        // trainee a conversation with the system administrators.
        if (! $request->toInbox() && ! $to instanceof User) {
            abort(404);
        }

        $body = (string) $request->validated('body');

        // One unit: a failure never leaves a conversation without its first
        // message.
        $thread = DB::transaction(function () use ($request, $user, $to, $body): Thread {
            $thread = $request->toInbox() || ! $to instanceof User
                ? $this->starter->withInbox($user)
                : $this->starter->withPerson($user, $to);

            Message::query()->create([
                'thread_id' => $thread->getKey(),
                'sender_id' => $user->getKey(),
                'body' => $body,
                'attachments' => [],
                'sent_at' => Clock::now(),
            ]);

            $thread->touch();

            return $thread;
        });

        $this->notices->posted($thread, $user, $body);

        return redirect()
            ->route('messages.index', ['thread' => $thread->getKey()])
            ->with('status', __('messages.sent'));
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
     * When the other side of a one-to-one conversation last read it — the
     * only thing the read marker may be built on. Group and announcement
     * threads have no single other side, so they get none. In the inbox the
     * other side is the system administrators: read when any of them read.
     */
    private function otherPartyReadAt(Thread $thread, User $user): ?\DateTimeInterface
    {
        if (ThreadPresenter::typeOf($thread)?->isOneToOne() !== true) {
            return null;
        }

        $others = ThreadParticipant::query()
            ->where('thread_id', $thread->getKey())
            ->where('user_id', '!=', $user->getKey());

        // In the inbox a system administrator's "read" is the supervisor's
        // reading, not a colleague's (D-118 review).
        if ($thread->isInbox() && ! $thread->isInboxOwner($user)) {
            $others->where('user_id', $thread->inboxOwnerId());
        }

        $readAt = $others->orderByDesc('last_read_at')->value('last_read_at');

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

        // In the announcement channel, an announcement to the cohort; anywhere
        // else, a message to the other members — on the platform now, and by
        // letter to whoever is offline (PRD §9.16.1, D-83). It only ever sent
        // the letter, to everyone, with the thread's empty title in it.
        $this->notices->posted($thread, $user, (string) $request->validated('body'));

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
    private function activeThread(Request $request, $threads, User $user): ?Thread
    {
        $requested = $request->query('thread');

        if (is_string($requested) && $requested !== '') {
            $match = $threads->first(
                static fn (Thread $thread): bool => (string) $thread->getKey() === $requested,
            );

            // On another page of the list: asked through the same visibility
            // rule, so a thread the account does not read is simply not
            // found, and an unknown id falls back rather than leaking its
            // existence.
            $match ??= Thread::query()
                ->visibleTo($user)
                ->with(['latestMessage.sender.profile', 'cohort', 'users.profile'])
                ->whereKey($requested)
                ->first();

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
