<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PublicationOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetFinalProjectAvailabilityRequest;
use App\Http\Requests\Admin\StoreFinalProjectRequest;
use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Presenters\Admin\FinalProjectGuidePanel;
use App\Presenters\Admin\FinalProjectSettings;
use App\Presenters\Admin\SubmissionFieldsPanel;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Cohorts\PrimaryCoordinator;
use App\Services\FinalProject\ProjectPublication;
use App\Services\FinalProject\SubmissionFields;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
 * D-127 — the supervisor no longer opens the tab: they make the project
 * AVAILABLE (`availability()`, its own press, never part of the settings
 * save), and opening it to the trainees is the cohort's primary coordinator's
 * press (Coordinator\FinalProjectController). Withdrawing locks the project at
 * once, and after hand-ins asks for a confirmation (`?confirm=withdraw`). The
 * move-to-open notice (D-77) went with the press. The same screen carries the
 * guide card (`?guide=ar|en` opens its editor); the guide's writes live in
 * FinalProjectGuideController.
 *
 * D-121 — the same screen shows the project's hand-in fields and opens their
 * editor (`?field=new|{id}`) or removal prompt (`?remove={id}`); the writes
 * live in FinalProjectFieldController. A project created here starts with the
 * default fields (SubmissionFields::DEFAULTS), which the supervisor then shapes.
 *
 * @see BR-15, BR-16, BR-23, BR-31 · FR-PROJ-10 · PRD §9.14 · D-77, D-109, D-110, D-121, D-127 · CONSTITUTION Art. 5
 */
final class FinalProjectController extends Controller
{
    /** How many guide versions the editor lists per page (Article 19). */
    private const VERSIONS_PER_PAGE = 20;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SubmissionFields $fields,
        private readonly ProjectPublication $publication,
        private readonly PrimaryCoordinator $primary,
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
                ->with(['unlocker.profile', 'availabler.profile'])
                ->first();

        $hasPrimaryCoordinator = $selectedCohort instanceof Cohort && $this->primary->has($selectedCohort);

        $handIns = $project instanceof FinalProject
            ? ProjectSubmission::query()->where('final_project_id', $project->getKey())->distinct()->count('user_id')
            : 0;

        return view('admin.final-project', [
            'hasPrimaryCoordinator' => $hasPrimaryCoordinator,
            'handInCount' => $handIns,
            'handInLabel' => trans_choice('coordinator.final_project.hand_ins', $handIns, ['count' => $handIns]),
            'confirmingWithdraw' => $project instanceof FinalProject
                && (bool) $project->is_unlocked
                && $handIns > 0
                && $request->query('confirm') === 'withdraw',
            'guidePanel' => $project instanceof FinalProject
                ? $this->guidePanel($request, $project, $hasPrimaryCoordinator, $cohorts)
                : null,
            'fieldsPanel' => $project instanceof FinalProject
                ? SubmissionFieldsPanel::from(
                    $project,
                    $project->fields()->get(),
                    self::queryId($request, 'field'),
                    self::queryId($request, 'remove'),
                )
                : null,
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
        $isNew = ! $project->exists;

        $before = $project->exists
            ? $this->audit->snapshot($project, ['title', 'due_at', 'max_score', 'allow_late'])
            : null;

        $project->fill($request->columns());

        $this->audit->log(
            action: $project->exists ? 'final_project.updated' : 'final_project.created',
            entity: $project,
            before: $before,
            after: $this->audit->snapshot($project, ['title', 'due_at', 'max_score', 'allow_late']),
            actor: $admin,
        );

        $project->save();

        // D-121 — a new project starts with the default hand-in fields, so
        // it is never opened with nothing to hand in. Every later save leaves
        // the fields to their own card. The trail first, then the write
        // (art. 8): a project just created has no fields, so the whole
        // default set is what is about to be written.
        if ($isNew) {
            $this->audit->log(
                action: 'final_project.fields_installed',
                entity: $project,
                before: null,
                after: ['fields' => count(SubmissionFields::DEFAULTS)],
                actor: $admin,
            );

            $this->fields->installDefaults($project);
        }

        return redirect()
            ->route('admin.finalProject.index', ['cohort' => $cohort->getKey()])
            ->with('status', __('admin.final_project.saved'));
    }

    /**
     * D-127 — make the project available for its primary coordinator to
     * publish, or withdraw it (which locks a published project at once). The
     * message says exactly what happened: who was told, and whether the
     * trainees lost the project.
     */
    public function availability(SetFinalProjectAvailabilityRequest $request, FinalProject $project): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $wasPublished = (bool) $project->is_unlocked;
        $outcome = $this->publication->setAvailability($project, $request->available(), $admin);

        /** @var Cohort $cohort */
        $cohort = Cohort::query()->findOrFail((string) $project->cohort_id);

        $redirect = redirect()->to(route('admin.finalProject.index', ['cohort' => $cohort->getKey()]).'#availability');

        return match (true) {
            $outcome !== PublicationOutcome::Changed => $redirect->with('warning', __('admin.final_project.availability_unchanged')),
            ! $request->available() => $redirect->with('status', $wasPublished
                ? __('admin.final_project.withdrawn_locked')
                : __('admin.final_project.withdrawn')),
            $this->primary->has($cohort) => $redirect->with('status', __('admin.final_project.made_available')),
            default => $redirect->with('warning', __('admin.final_project.made_available_no_primary')),
        };
    }

    /**
     * The guide card, and — on `?guide=ar|en` — its editor with one page of
     * that language's history (D-127).
     *
     * @param  \Illuminate\Support\Collection<int, Cohort>  $cohorts
     */
    private function guidePanel(Request $request, FinalProject $project, bool $hasPrimaryCoordinator, $cohorts): FinalProjectGuidePanel
    {
        $guides = FinalProjectGuide::query()
            ->where('final_project_id', $project->getKey())
            ->with('currentVersion')
            ->get();

        $editing = $request->query('guide');
        $editing = is_string($editing) && in_array($editing, FinalProjectGuide::LOCALES, true) ? $editing : null;

        $editedGuide = $editing === null ? null : $guides->firstWhere('locale', $editing);

        $history = $editedGuide instanceof FinalProjectGuide
            ? FinalProjectGuideVersion::query()
                ->where('final_project_guide_id', $editedGuide->getKey())
                ->with('author.profile')
                ->orderByDesc('version')
                ->paginate(self::VERSIONS_PER_PAGE, ['*'], 'versions')
                ->withQueryString()
            : null;

        // Other cohorts whose project has at least one saved page (D-127).
        $sources = FinalProject::query()
            ->whereKeyNot($project->getKey())
            ->whereHas('guides', static fn ($query) => $query->whereHas('versions'))
            ->pluck('cohort_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return FinalProjectGuidePanel::from(
            $project,
            $guides,
            $editing,
            $history,
            $hasPrimaryCoordinator,
            Options::fromModels(
                $cohorts->filter(static fn (Cohort $cohort): bool => in_array((string) $cohort->getKey(), $sources, true)),
                static fn (Cohort $cohort): string => (string) $cohort->getAttribute('name'),
            ),
        );
    }

    /**
     * A uuid-shaped id from the query string, or 'new', or nothing. Anything
     * else opens nothing rather than reaching a query (art. 7).
     */
    private static function queryId(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^(new|[0-9a-f-]{36})$/i', $value) === 1 ? $value : null;
    }
}
