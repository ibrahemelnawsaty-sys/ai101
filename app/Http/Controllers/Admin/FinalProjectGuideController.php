<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PublicationOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CopyFinalProjectGuideRequest;
use App\Http\Requests\Admin\RestoreFinalProjectGuideRequest;
use App\Http\Requests\Admin\SaveFinalProjectGuideRequest;
use App\Http\Requests\Admin\SetFinalProjectGuideAvailabilityRequest;
use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\User;
use App\Services\Cohorts\PrimaryCoordinator;
use App\Services\FinalProject\GuideContent;
use App\Services\FinalProject\GuidePublication;
use Illuminate\Http\RedirectResponse;

/**
 * The general supervisor's side of the final project's guide (D-127): saving a
 * page from the editor or an uploaded file, bringing an older version back,
 * copying another cohort's pages, and making a language available or not.
 *
 * Publishing is not here: it is the cohort's primary coordinator's press
 * (Coordinator\FinalProjectController). Every write answers to its own
 * FormRequest and to FinalProjectPolicy::update, and the route model binding
 * resolves the project from the URL — a project id from another cohort still
 * answers to the same admin-only policy.
 *
 * @see D-127 · BR-31 · CONSTITUTION Art. 5, Art. 8
 */
final class FinalProjectGuideController extends Controller
{
    public function __construct(
        private readonly GuideContent $content,
        private readonly GuidePublication $publication,
        private readonly PrimaryCoordinator $primary,
    ) {}

    public function save(SaveFinalProjectGuideRequest $request, FinalProject $project, string $locale): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $before = $this->currentNumber($project, $locale);
        $after = $this->content->save($project, $locale, $request->page(), $request->source(), $admin)->version;

        // Saving the very page on show writes nothing, and says so.
        return $before === $after
            ? $this->back($project, $locale)->with('warning', __('admin.final_project.guide.unchanged_page'))
            : $this->back($project, $locale)->with('status', __('admin.final_project.guide.saved'));
    }

    public function restore(RestoreFinalProjectGuideRequest $request, FinalProject $project, string $locale): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $guide = FinalProjectGuide::query()
            ->where('final_project_id', $project->getKey())
            ->where('locale', $locale)
            ->first();

        $before = $this->currentNumber($project, $locale);

        $restored = $guide instanceof FinalProjectGuide
            ? $this->content->restore($guide, $request->version(), $admin)
            : null;

        if ($restored === null) {
            return $this->back($project, $locale)->withErrors(['version' => __('admin.final_project.guide.errors.no_version')]);
        }

        // The version asked for is already the page on show: nothing new.
        return $restored->version === $before
            ? $this->back($project, $locale)->with('warning', __('admin.final_project.guide.unchanged_page'))
            : $this->back($project, $locale)->with('status', __('admin.final_project.guide.restored', ['number' => $request->version()]));
    }

    public function copy(CopyFinalProjectGuideRequest $request, FinalProject $project): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        /** @var FinalProject $source */
        $source = $request->sourceProject();

        $copied = $this->content->copyFrom($source, $project, $admin);

        return redirect()
            ->to(route('admin.finalProject.index', ['cohort' => $project->cohort_id]).'#guide')
            ->with('status', $copied > 0
                ? __('admin.final_project.guide.copied')
                : __('admin.final_project.guide.copied_nothing'));
    }

    public function availability(SetFinalProjectGuideAvailabilityRequest $request, FinalProject $project, string $locale): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $guide = $this->content->guideFor($project, $locale);
        $outcome = $this->publication->setAvailability($guide, $request->available(), $admin);

        $redirect = redirect()->to(route('admin.finalProject.index', ['cohort' => $project->cohort_id]).'#guide');

        return match (true) {
            $outcome === PublicationOutcome::NoContent => $redirect->withErrors(['available' => __('admin.final_project.guide.errors.no_content')]),
            $outcome !== PublicationOutcome::Changed => $redirect->with('warning', __('admin.final_project.guide.unchanged')),
            ! $request->available() => $redirect->with('status', __('admin.final_project.guide.withdrawn')),
            $this->primary->has($this->cohortOf($project)) => $redirect->with('status', __('admin.final_project.guide.made_available')),
            default => $redirect->with('warning', __('admin.final_project.guide.made_available_no_primary')),
        };
    }

    private function currentNumber(FinalProject $project, string $locale): ?int
    {
        $guide = FinalProjectGuide::query()
            ->where('final_project_id', $project->getKey())
            ->where('locale', $locale)
            ->first();

        return $guide instanceof FinalProjectGuide ? $this->content->current($guide)?->version : null;
    }

    private function cohortOf(FinalProject $project): Cohort
    {
        /** @var Cohort $cohort */
        $cohort = Cohort::query()->findOrFail((string) $project->cohort_id);

        return $cohort;
    }

    private function back(FinalProject $project, string $locale): RedirectResponse
    {
        return redirect()->to(
            route('admin.finalProject.index', ['cohort' => $project->cohort_id, 'guide' => $locale]).'#guide-editor',
        );
    }
}
