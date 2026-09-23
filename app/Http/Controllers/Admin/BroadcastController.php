<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BroadcastKind;
use App\Enums\CohortStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendBroadcastRequest;
use App\Http\Requests\Admin\SendReminderRequest;
use App\Models\Broadcast;
use App\Models\Cohort;
use App\Presenters\Admin\BroadcastRow;
use App\Presenters\Support\Options;
use App\Services\Notifications\BroadcastRefused;
use App\Services\Notifications\CohortBroadcaster;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The administrator's send screen: write to a cohort's trainees, and send
 * the session and assignment reminders by hand (D-87).
 *
 * WHY IT EXISTS
 * The owner had no way to tell a cohort anything by e-mail: the only path to
 * trainees was posting in the announcements channel, whose letter carries a
 * 120-character excerpt. And the reminders were automatic only — right for
 * the routine, no help the day a trainer moves the whole week or a deadline
 * slips past half the cohort. The automatic reminders stay exactly as they
 * are; this adds the manual ones beside them.
 *
 * Every write goes through its FormRequest and BroadcastPolicy (admin, never
 * inside a preview), and every send is recorded, audited and listed below the
 * forms, so what trainees were told is never a matter of memory.
 *
 * @see PRD §9.16, §9.16.1, §9.18 · BR-22, BR-23, BR-28, BR-33 · D-83, D-87
 */
final class BroadcastController extends Controller
{
    private const PER_PAGE = 20;

    /** The cohorts a send is offered for: every one that still has trainees. */
    private const REACHABLE = [
        CohortStatus::Upcoming,
        CohortStatus::Open,
        CohortStatus::Running,
        CohortStatus::Completed,
    ];

    public function __construct(private readonly CohortBroadcaster $broadcaster) {}

    public function index(): View
    {
        $this->authorize('viewAny', Broadcast::class);

        $cohorts = Cohort::query()
            ->whereIn('status', array_map(static fn (CohortStatus $s): string => $s->value, self::REACHABLE))
            ->orderByDesc('start_date')
            ->get();

        // Each option says who it reaches, so the count is visible before the
        // send and needs no script: trainees in the cohort, and how many of
        // them take the administration's messages by e-mail.
        $cohortOptions = Options::fromModels($cohorts, function (Cohort $cohort): string {
            $reach = $this->broadcaster->reach((string) $cohort->getKey());

            return (string) __('admin.broadcasts.cohort_option', [
                'name' => (string) $cohort->getAttribute('name'),
                'trainees' => self::trainees($reach['participants']),
                'reachable' => self::trainees($reach['byEmail']),
            ]);
        });

        $history = Broadcast::query()
            ->with(['cohort', 'sender.profile'])
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(static fn (Broadcast $broadcast): BroadcastRow => BroadcastRow::from($broadcast));

        return view('admin.broadcasts', [
            'contextLabel' => null,
            'cohortOptions' => $cohortOptions,
            'history' => $history,
            'cooldownMinutes' => max(1, (int) config('athar.broadcasts.cooldown_minutes', 10)),
            'bodyMax' => max(1, (int) config('athar.broadcasts.body_max', 5000)),
            'errorState' => null,
        ]);
    }

    /** Send the administrator's own message. */
    public function store(SendBroadcastRequest $request): RedirectResponse
    {
        /** @var \App\Models\User $admin */
        $admin = $request->user();

        try {
            $sent = $this->broadcaster->message(
                $admin,
                $request->cohort(),
                $request->subjectLine(),
                $request->bodyText(),
                $request->inApp(),
            );
        } catch (BroadcastRefused $refused) {
            return back()->withInput()->withErrors(['broadcast' => $refused->reason()], 'broadcast');
        }

        return redirect()
            ->route('admin.broadcasts.index')
            ->with('status', __('admin.broadcasts.sent', self::counts($sent)));
    }

    /** Send a reminder by hand: every upcoming session, or everyone's open work. */
    public function remind(SendReminderRequest $request): RedirectResponse
    {
        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $cohort = $request->cohort();

        try {
            $sent = match ($request->kind()) {
                BroadcastKind::Sessions => $this->broadcaster->sessions($admin, $cohort),
                default => $this->broadcaster->assignments($admin, $cohort),
            };
        } catch (BroadcastRefused $refused) {
            return back()->withInput()->withErrors(['reminder' => $refused->reason()], 'reminder');
        }

        return redirect()
            ->route('admin.broadcasts.index')
            ->with('status', __('admin.broadcasts.reminded', self::counts($sent)));
    }

    /**
     * «5 trainees», «two trainees», «one trainee» — Arabic has a form for one,
     * for two, for three to ten and for eleven up (CLAUDE.md: the count agrees
     * with its noun).
     */
    private static function trainees(int $count): string
    {
        return trans_choice('admin.broadcasts.trainees', $count, ['count' => $count]);
    }

    /**
     * @return array{trainees: string, emails: string}
     */
    private static function counts(Broadcast $sent): array
    {
        $letters = (int) $sent->getAttribute('emails');

        return [
            'trainees' => self::trainees((int) $sent->getAttribute('recipients')),
            'emails' => trans_choice('admin.broadcasts.letters', $letters, ['count' => $letters]),
        ];
    }
}
