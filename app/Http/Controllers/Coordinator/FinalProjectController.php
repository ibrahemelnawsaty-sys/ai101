<?php

declare(strict_types=1);

namespace App\Http\Controllers\Coordinator;

use App\Enums\PublicationOutcome;
use App\Http\Controllers\Concerns\ReadsCohortScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\SetFinalProjectGuidePublicationRequest;
use App\Http\Requests\Coordinator\SetFinalProjectPublicationRequest;
use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Presenters\Coordinator\FinalProjectPanel;
use App\Services\Cohorts\PrimaryCoordinator;
use App\Services\FinalProject\GuidePublication;
use App\Services\FinalProject\ProjectPublication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The coordinator's final-project tab (D-127).
 *
 * The owner's rule: the general supervisor enters the project and makes it
 * available; the cohort's PRIMARY coordinator publishes it to the trainees —
 * the project, and its guide language by language. Every coordinator of the
 * cohort reads this tab; the presses answer to FinalProjectPolicy /
 * FinalProjectGuidePolicy `publish` and `unpublish`, which name the primary
 * coordinator alone. No hand-ins and no marks here: the coordinator's role is
 * preparation (D-105).
 *
 * The project comes from the cohort `cohort.scope` resolved; a press names the
 * project in its URL, and a project of a cohort this account does not
 * primarily coordinate answers 403.
 *
 * @see D-127 · D-105, D-124 · BR-15, BR-16 · CONSTITUTION Art. 5, Art. 17, Art. 22
 */
final class FinalProjectController extends Controller
{
    use ReadsCohortScope;

    public function __construct(
        private readonly ProjectPublication $projects,
        private readonly GuidePublication $guides,
        private readonly PrimaryCoordinator $primary,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $cohort = $this->scopedCohort($request);

        $project = $cohort instanceof Cohort
            ? FinalProject::query()
                ->where('cohort_id', $cohort->getKey())
                ->with(['unlocker.profile'])
                ->first()
            : null;

        if (! $cohort instanceof Cohort || ! $project instanceof FinalProject) {
            return view('coordinator.final-project', [
                'contextLabel' => $cohort?->getAttribute('name'),
                'panel' => null,
                'hasCohort' => $cohort instanceof Cohort,
                'errorState' => null,
            ]);
        }

        $this->authorize('coordinate', $project);

        $guides = FinalProjectGuide::query()
            ->where('final_project_id', $project->getKey())
            ->with('currentVersion')
            ->get();

        $primaryId = $this->primary->idOf($cohort);

        return view('coordinator.final-project', [
            'contextLabel' => $cohort->getAttribute('name'),
            'panel' => FinalProjectPanel::from(
                $project,
                $guides,
                $user,
                $primaryId === (string) $user->getKey(),
                $primaryId !== null,
                ProjectSubmission::query()->where('final_project_id', $project->getKey())->distinct()->count('user_id'),
                $request->query('confirm') === 'unpublish',
            ),
            'hasCohort' => true,
            'errorState' => null,
        ]);
    }

    public function publication(SetFinalProjectPublicationRequest $request, FinalProject $project): RedirectResponse
    {
        /** @var User $coordinator */
        $coordinator = $request->user();

        $publishing = $request->publishing();

        $outcome = $publishing
            ? $this->projects->publish($project, $coordinator)
            : $this->projects->unpublish($project, $coordinator);

        return $this->back($project, '#publish-project', $outcome, match ($outcome) {
            PublicationOutcome::Changed => $publishing
                ? __('coordinator.final_project.published')
                : __('coordinator.final_project.unpublished'),
            PublicationOutcome::NotAvailable => __('coordinator.final_project.errors.not_available'),
            default => __('coordinator.final_project.unchanged'),
        });
    }

    public function guidePublication(SetFinalProjectGuidePublicationRequest $request, FinalProject $project, string $locale): RedirectResponse
    {
        /** @var User $coordinator */
        $coordinator = $request->user();

        /** @var FinalProjectGuide $guide */
        $guide = $request->guide();

        $publishing = $request->publishing();

        $outcome = $publishing
            ? $this->guides->publish($guide, $coordinator)
            : $this->guides->unpublish($guide, $coordinator);

        return $this->back($project, '#publish-guide', $outcome, match ($outcome) {
            PublicationOutcome::Changed => $publishing
                ? __('coordinator.final_project.guide_published')
                : __('coordinator.final_project.guide_unpublished'),
            PublicationOutcome::NotAvailable => __('coordinator.final_project.errors.guide_not_available'),
            PublicationOutcome::NeedsPrimaryLocale => __('coordinator.final_project.errors.needs_arabic'),
            default => __('coordinator.final_project.unchanged'),
        });
    }

    /**
     * Back to the tab, the message in the tone of what happened: done, nothing
     * to do (a second click), or refused because the state moved on — never a
     * 403 for a press this account was entitled to make (Article 15).
     */
    private function back(FinalProject $project, string $anchor, PublicationOutcome $outcome, mixed $message): RedirectResponse
    {
        $tone = match ($outcome) {
            PublicationOutcome::Changed => 'status',
            PublicationOutcome::Unchanged => 'warning',
            default => 'error',
        };

        return redirect()
            ->to(route('coordinator.finalProject', ['cohort' => $project->cohort_id]).$anchor)
            ->with($tone, $message);
    }
}
