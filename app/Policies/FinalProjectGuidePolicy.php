<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;
use App\Services\Cohorts\PrimaryCoordinator;

/**
 * Who reads, makes available and publishes the final project's guide (D-127).
 *
 * The owner's rule, as an allow-list — anything not granted here is refused:
 *
 *   general supervisor  edits (through FinalProjectPolicy::update), makes each
 *                       language available or withdraws it, reads it always
 *   primary coordinator reads it once available, publishes it and takes it
 *                       down — per language, English never before Arabic
 *   other coordinators  read it once published
 *   and trainers
 *   trainees            read it once published AND the project itself is
 *                       published (BR-15/BR-16 still decide what reaches them)
 *
 * "Published" for the English page always means "and the Arabic page is
 * published too": English is a language of the guide, not a second guide.
 *
 * @see D-127 · D-124 · BR-15, BR-16, BR-22, BR-23 · CONSTITUTION Art. 5, Art. 22
 */
final class FinalProjectGuidePolicy
{
    use InteractsWithScope;

    public function view(User $user, FinalProjectGuide $guide): bool
    {
        $project = $this->project($guide);

        if (! $project instanceof FinalProject) {
            return false;
        }

        $cohortId = (string) $project->cohort_id;

        if ($this->admin($user)) {
            return true;
        }

        if ($this->isPrimaryCoordinator($user, $project) && (bool) $guide->is_available) {
            return true;
        }

        if (! $this->visibleToCohort($guide, $project)) {
            return false;
        }

        if ($this->trainerOf($user, $cohortId) || $this->coordinatorOf($user, $cohortId)) {
            return true;
        }

        return (bool) $project->is_unlocked && $this->participantOf($user, $cohortId);
    }

    /** Making a language available, or withdrawing it: the general supervisor. */
    public function makeAvailable(User $user, FinalProjectGuide $guide): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    /**
     * Publishing a language: available first, the primary coordinator only,
     * and English only once Arabic is out.
     */
    public function publish(User $user, FinalProjectGuide $guide): bool
    {
        $project = $this->project($guide);

        return $project instanceof FinalProject
            && $this->writesAllowed()
            && (bool) $guide->is_available
            && ($guide->isPrimaryLocale() || $this->primaryPublished($guide))
            && $this->isPrimaryCoordinator($user, $project);
    }

    /** Taking a published language down: the primary coordinator. */
    public function unpublish(User $user, FinalProjectGuide $guide): bool
    {
        $project = $this->project($guide);

        return $project instanceof FinalProject
            && $this->writesAllowed()
            && (bool) $guide->is_published
            && $this->isPrimaryCoordinator($user, $project);
    }

    /** Published for the cohort: this language, and — for English — Arabic too. */
    private function visibleToCohort(FinalProjectGuide $guide, FinalProject $project): bool
    {
        if (! (bool) $guide->is_published) {
            return false;
        }

        return $guide->isPrimaryLocale() || $this->primaryPublished($guide, $project);
    }

    private function primaryPublished(FinalProjectGuide $guide, ?FinalProject $project = null): bool
    {
        return FinalProjectGuide::query()
            ->where('final_project_id', $project?->getKey() ?? $guide->final_project_id)
            ->where('locale', FinalProjectGuide::PRIMARY_LOCALE)
            ->where('is_published', true)
            ->exists();
    }

    private function project(FinalProjectGuide $guide): ?FinalProject
    {
        /** @var FinalProject|null $project */
        $project = $guide->relationLoaded('project')
            ? $guide->getRelation('project')
            : FinalProject::query()->find((string) $guide->final_project_id);

        return $project;
    }

    /** The cohort's primary coordinator (D-124), still active and coordinating. */
    private function isPrimaryCoordinator(User $user, FinalProject $project): bool
    {
        $cohort = Cohort::query()->find((string) $project->cohort_id);

        return $cohort instanceof Cohort
            && $this->roles->isActive($user)
            && app(PrimaryCoordinator::class)->idOf($cohort) === (string) $user->getKey();
    }
}
