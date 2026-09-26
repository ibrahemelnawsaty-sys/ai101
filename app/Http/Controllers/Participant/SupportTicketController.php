<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketEntryType;
use App\Enums\SupportTicketStatus;
use App\Exceptions\FileException;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\AssignTicketRequest;
use App\Http\Requests\Support\CloseTicketRequest;
use App\Http\Requests\Support\EscalateTicketRequest;
use App\Http\Requests\Support\NoteTicketRequest;
use App\Http\Requests\Support\OpenTicketRequest;
use App\Http\Requests\Support\ReplyTicketRequest;
use App\Http\Requests\Support\ResolveTicketRequest;
use App\Http\Requests\Support\ReturnTicketRequest;
use App\Models\Cohort;
use App\Models\SupportTicket;
use App\Models\User;
use App\Presenters\Tickets\TicketPage;
use App\Presenters\Tickets\TicketRow;
use App\Services\Permissions\RoleResolver;
use App\Services\Tickets\TicketAttachments;
use App\Services\Tickets\TicketRouting;
use App\Services\Tickets\TicketWorkflow;
use App\Services\Time\Clock;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * «Support» — support tickets (D-124), for everyone who takes part in them:
 * the participant who opens one, and the support team that moves it.
 *
 * Every list starts from SupportTicket::scopeVisibleTo and every ticket from
 * SupportTicketPolicy::view, the same rule written twice on purpose: a list
 * never offers what the page would refuse, and changing an id in the address
 * answers 403 (BR-22). Every change goes through TicketWorkflow, the one
 * writer, which asks the rule again under the ticket's lock.
 *
 * A preview reads and never writes (BR-33): every write route sits behind
 * `not.impersonating`, and opening a ticket writes no read marker (BR-34).
 *
 * @see D-124 · BR-22, BR-23, BR-33, BR-34 · CONSTITUTION art. 5, art. 17, art. 22
 */
final class SupportTicketController extends Controller
{
    use ResolvesActiveCohort;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'support';

    /** Tickets per page: every list past 50 is paginated (art. 19). */
    private const PER_PAGE = 50;

    public function __construct(
        private readonly TicketWorkflow $workflow,
        private readonly TicketRouting $routing,
        private readonly RoleResolver $roles,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', SupportTicket::class);

        $staff = $this->isStaff($user);
        $tab = $staff ? ($request->query('tab') === 'all' ? 'all' : 'waiting') : 'mine';
        $now = Clock::now();

        $rows = [];
        $pages = null;
        $waiting = 0;
        $failed = false;

        try {
            $query = SupportTicket::query()
                ->visibleTo($user)
                ->with(['cohort', 'opener.profile']);

            if ($tab === 'waiting') {
                $query->waitingOn($user);
            }

            $pages = $query->orderByDesc('last_activity_at')
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString();

            foreach ($pages->items() as $ticket) {
                $rows[] = TicketRow::from($ticket, $staff, $now);
            }

            $waiting = $staff ? SupportTicket::query()->visibleTo($user)->waitingOn($user)->count() : 0;
        } catch (\Throwable $failure) {
            // The page says what happened and what to do; the log keeps the
            // reason, without the person's details (art. 12, art. 17).
            Log::error('support.index_failed', ['user_id' => $user->getKey(), 'exception' => $failure::class]);
            $failed = true;
        }

        return view('participant.support.index', [
            'rows' => $rows,
            'pages' => $pages,
            'isStaff' => $staff,
            'tab' => $tab,
            'waitingCount' => $waiting,
            'waitingLabel' => trans_choice('support.count.waiting', $waiting, ['count' => $waiting]),
            'canOpen' => $user->can('create', SupportTicket::class),
            // A trainee with no cohort cannot open one (SupportTicketPolicy::
            // create); the page says why, and where to write instead.
            'contactEmail' => (string) config('athar.email'),
            'errorState' => $failed ? true : null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::of($rows === [], $failed),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', SupportTicket::class);

        $files = TicketAttachments::maxFiles();

        return view('participant.support.create', [
            'categories' => array_map(
                static fn (SupportTicketCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
                SupportTicketCategory::cases(),
            ),
            'accept' => TicketAttachments::ACCEPT,
            'maxFiles' => $files,
            'maxMb' => TicketAttachments::maxMegabytes(),
            'filesHint' => (string) __('support.create.files_hint', [
                'count' => trans_choice('support.count.files', $files, ['count' => $files]),
                'size' => TicketAttachments::maxMegabytes(),
            ]),
            'subjectMax' => OpenTicketRequest::subjectMax(),
            'bodyMax' => OpenTicketRequest::bodyMax(),
            'errorState' => null,
        ]);
    }

    public function store(OpenTicketRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $ticket = $this->workflow->open(
                $user,
                $this->participantCohort($user),
                $request->category(),
                $request->subject(),
                $request->body(),
                $request->link(),
                $request->uploads(),
            );
        } catch (FileException $failure) {
            return back()->withInput()->withErrors(['attachments' => $this->fileRefusal($failure)]);
        }

        return redirect()
            ->route('support.show', $ticket)
            ->with('status', __('support.flash.opened', ['number' => $ticket->number]));
    }

    public function show(Request $request, SupportTicket $ticket): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('view', $ticket);

        $staffView = $this->routing->readsAsStaff($user, $ticket);

        $ticket->load(['cohort', 'opener.profile', 'assignee.profile']);

        // The participant's page never loads an internal line at all
        // (TicketEntryLine refuses one again).
        $entries = $ticket->entries()
            ->when(! $staffView, static fn ($query) => $query->shownToParticipant())
            ->with(['actor.profile', 'target.profile', 'attachments'])
            ->get();

        return view('participant.support.show', [
            'ticket' => TicketPage::from($ticket, $entries, $user, $staffView, Clock::now()),
            'accept' => TicketAttachments::ACCEPT,
            'maxFiles' => TicketAttachments::maxFiles(),
            'maxMb' => TicketAttachments::maxMegabytes(),
            'filesHint' => (string) __('support.create.files_hint', [
                'count' => trans_choice('support.count.files', TicketAttachments::maxFiles(), ['count' => TicketAttachments::maxFiles()]),
                'size' => TicketAttachments::maxMegabytes(),
            ]),
            'bodyMax' => ReplyTicketRequest::bodyMax(),
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::NORMAL,
        ]);
    }

    // ------------------------------------------------------------ participant

    public function reply(ReplyTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reopens = $ticket->status === SupportTicketStatus::Resolved;

        try {
            $this->workflow->reply($user, $ticket, $request->body(), $request->link(), $request->uploads());
        } catch (FileException $failure) {
            return back()->withInput()->withErrors(['attachments' => $this->fileRefusal($failure)]);
        }

        return $this->backTo($ticket, $reopens ? 'support.flash.reopened' : 'support.flash.replied');
    }

    public function close(CloseTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->workflow->close($user, $ticket);

        return $this->backTo($ticket, 'support.flash.closed');
    }

    // ------------------------------------------------------------ the team

    public function note(NoteTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $entry = $this->workflow->note($user, $ticket, $request->body(), $request->internal(), $request->link(), $request->uploads());
        } catch (FileException $failure) {
            return back()->withInput()->withErrors(['attachments' => $this->fileRefusal($failure)]);
        }

        return $this->backTo($ticket, $entry->type === SupportTicketEntryType::Message ? 'support.flash.messaged' : 'support.flash.noted');
    }

    public function resolve(ResolveTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->workflow->resolve($user, $ticket, $request->summary());

        return $this->backTo($ticket, 'support.flash.resolved');
    }

    public function escalate(EscalateTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $moved = $this->workflow->escalate($user, $ticket, $request->note());

        return $this->afterMove($user, $moved, 'support.flash.escalated');
    }

    public function returnDown(ReturnTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $moved = $this->workflow->returnDown($user, $ticket, $request->coordinatorId(), $request->note());

        return $this->afterMove($user, $moved, 'support.flash.returned');
    }

    public function assign(AssignTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $moved = $this->workflow->assign($user, $ticket, $request->coordinatorId(), $request->note());
        $moved->load('assignee.profile');
        $assignee = $moved->getRelation('assignee');
        $name = $assignee instanceof User ? (string) ($assignee->profile?->getAttribute('full_name_ar') ?? $assignee->getAttribute('email')) : '';

        return redirect()
            ->route('support.show', $moved)
            ->with('status', __('support.flash.assigned', ['name' => $name]));
    }

    // ------------------------------------------------------------ internals

    /**
     * A ticket moved away may no longer be readable by whoever moved it — a
     * system administrator returning it reads it still, a coordinator who
     * moved it up reads it still; if not, the list is where they land.
     */
    private function afterMove(User $user, SupportTicket $moved, string $flash): RedirectResponse
    {
        $message = __($flash, ['to' => $moved->level->label()]);

        if (! $user->can('view', $moved)) {
            return redirect()->route('support.index')->with('status', $message);
        }

        return redirect()->route('support.show', $moved)->with('status', $message);
    }

    private function backTo(SupportTicket $ticket, string $flash): RedirectResponse
    {
        return redirect()->route('support.show', $ticket)->with('status', __($flash));
    }

    /**
     * The storage service's refusal, in the tickets' own words: its generic
     * wording ("upload a document, an image or an archive") would send a
     * participant looking for formats a ticket does not take.
     */
    private function fileRefusal(FileException $failure): string
    {
        $files = TicketAttachments::maxFiles();

        return match ($failure->langKey()) {
            'errors.file.mime_not_allowed', 'errors.file.executable_rejected' => (string) __('support.errors.file_type'),
            'errors.file.too_large' => (string) __('support.errors.file_size', ['size' => TicketAttachments::maxMegabytes()]),
            'errors.file.too_many' => (string) __('support.errors.files_count', ['count' => trans_choice('support.count.files', $files, ['count' => $files])]),
            default => $failure->localizedMessage(),
        };
    }

    /**
     * Anyone who reads tickets as support staff: a coordinator, a general
     * supervisor, a system administrator — whatever else the account is. Their
     * own tickets, if they opened any as a participant, are in "all tickets".
     */
    private function isStaff(User $user): bool
    {
        return $this->roles->hasAnyRole($user, ['coordinator', 'admin', 'system_admin']);
    }

    /**
     * The cohort a new ticket belongs to: the one the participant is looking
     * at, when they sit in it as a participant — otherwise their first such
     * cohort. None means the general supervisor receives it (D-124).
     */
    private function participantCohort(User $user): ?Cohort
    {
        $cohorts = $this->roles->participantCohortIds($user);

        if ($cohorts === []) {
            return null;
        }

        $active = $this->activeCohortId($user);
        $id = $active !== null && in_array($active, $cohorts, true) ? $active : $cohorts[0];

        return Cohort::query()->find($id);
    }
}
