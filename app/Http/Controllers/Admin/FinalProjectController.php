<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Events\FinalProjectUnlocked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFinalProjectRequest;
use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\User;
use App\Presenters\Admin\FinalProjectSettings;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\CohortAudience;
use App\Services\Notifications\InAppNotifier;
use App\Services\Time\Clock;
use App\Support\Dates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The final project, from the administrator's side: writing the brief, the
 * deadline, the ceiling, the late policy, and opening the tab (D-109, D-110).
 *
 * WHY THIS SCREEN EXISTS
 * Nothing ever wrote a final project's content before this: `title`, `brief`,
 * `requirements`, `due_at`, `max_score` and `attachments` were seed-only
 * columns with no form anywhere. The trainer's own unlock toggle covered
 * opening the tab, and the owner asked that this move here too, entirely
 * separate from the trainer's screen — so the two roles can never maintain
 * the same settings for the same cohort.
 *
 * The move-to-open notification (PRD §9.14.1, §9.16.1) is unchanged: it still
 * fires once, only on the transition from locked to unlocked (D-77), through
 * the same FinalProjectUnlocked event the trainer's screen used to dispatch.
 *
 * @see BR-15, BR-16, BR-23 · PRD §9.14 · D-77, D-109, D-110 · CONSTITUTION Art. 5
 */
final class FinalProjectController extends Controller
{
    /** Notification matrix slug of PRD §9.16.1, stored in notifications.type. */
    public const NOTIFICATION_TYPE_UNLOCKED = 'final_project_unlocked';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InAppNotifier $notifier,
        private readonly CohortAudience $audience,
    ) {}

    public function index(Request $request): View
    {
        $cohorts = Cohort::query()->orderByDesc('start_date')->get();

        $cohortId = $request->query('cohort');
        $cohortId = is_string($cohortId) && $cohortId !== '' ? $cohortId : null;

        $selectedCohort = $cohortId === null ? null : $cohorts->firstWhere('id', $cohortId);

        $project = $selectedCohort === null
            ? null
            : FinalProject::query()
                ->where('cohort_id', $selectedCohort->getKey())
                ->with('unlocker.profile')
                ->first();

        return view('admin.final-project', [
            'contextLabel' => $selectedCohort?->getAttribute('name'),
            'cohortOptions' => Options::fromModels(
                $cohorts,
                static fn (Cohort $c): string => (string) $c->getAttribute('name'),
            ),
            'selectedCohortId' => $selectedCohort?->getKey(),
            'settings' => match (true) {
                $selectedCohort === null => null,
                $project instanceof FinalProject => FinalProjectSettings::from($project),
                default => FinalProjectSettings::blank((string) $selectedCohort->getKey()),
            },
            'errorState' => null,
        ]);
    }

    /** Create or update the one project row a cohort may have. */
    public function store(StoreFinalProjectRequest $request): RedirectResponse
    {
        $cohort = $request->cohort();

        /** @var User $admin */
        $admin = $request->user();

        $project = FinalProject::query()->firstOrNew(['cohort_id' => $cohort->getKey()]);
        $wasUnlocked = (bool) $project->getAttribute('is_unlocked');

        $before = $project->exists
            ? $this->audit->snapshot($project, ['title', 'due_at', 'max_score', 'allow_late', 'is_unlocked'])
            : null;

        $unlocks = $request->unlocks();

        $project->fill($request->columns());
        $project->setAttribute('unlocked_at', $unlocks ? ($project->getAttribute('unlocked_at') ?? Clock::now()) : null);
        $project->setAttribute('unlocked_by', $unlocks ? ($project->getAttribute('unlocked_by') ?? $admin->getKey()) : null);

        $this->audit->log(
            action: $project->exists ? 'final_project.updated' : 'final_project.created',
            entity: $project,
            before: $before,
            after: $this->audit->snapshot($project, ['title', 'due_at', 'max_score', 'allow_late', 'is_unlocked']),
            actor: $admin,
        );

        $project->save();

        // Only the move from locked to unlocked announces (D-77): saving an
        // already-open project again, or one that stays locked, writes no
        // second notice and sends no second letter.
        if ($unlocks && ! $wasUnlocked) {
            $this->notifyCohort($project);

            FinalProjectUnlocked::dispatch(
                (string) $project->getAttribute('cohort_id'),
                Dates::dateTime($project->getAttribute('due_at')),
            );
        }

        return redirect()
            ->route('admin.finalProject.index', ['cohort' => $cohort->getKey()])
            ->with('status', __('admin.final_project.saved'));
    }

    /**
     * PRD §9.14.1 requires an immediate notice to every participant of the
     * cohort the moment the tab is opened.
     *
     * @see BR-15 · PRD §9.14.1, §9.16.1
     */
    private function notifyCohort(FinalProject $project): void
    {
        $at = Clock::now();
        $link = Route::has('finalProject') ? route('finalProject') : null;

        $replacements = [
            'datetime' => Dates::dateTime($project->getAttribute('due_at')),
        ];

        $title = (string) __('notifications.types.final_project_unlocked.title', $replacements);
        $body = (string) __('notifications.types.final_project_unlocked.body', $replacements);

        $this->notifier->notify(
            $this->audience->participants((string) $project->getAttribute('cohort_id'))
                ->map(static fn (User $user): string => (string) $user->getKey()),
            self::NOTIFICATION_TYPE_UNLOCKED,
            $title,
            $body,
            $link,
            $at,
        );
    }
}
