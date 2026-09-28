<?php

declare(strict_types=1);

namespace App\Services\FinalProject;

use App\Enums\PublicationOutcome;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The guide's two steps, per language (D-127): the general supervisor makes a
 * language available, the cohort's primary coordinator publishes it.
 *
 *   · a language with no page saved cannot be made available;
 *   · withdrawing availability takes the language down in the same write;
 *   · English is never published before Arabic, and taking Arabic down —
 *     unpublished or withdrawn — takes English down with it, so no screen
 *     ever says "published" of a page nobody can see;
 *   · the first time the Arabic page is published, the cohort's trainers are
 *     told — and the trainees too, if the project itself is already open.
 *     If it is not, they are told when it opens (ProjectPublication::publish),
 *     in the same notice. Neither is ever told twice (`staff_announced_at`,
 *     `announced_at`).
 *
 * Every change re-reads the rows under a lock — the project first, then the
 * guide, the order ProjectPublication uses too — and writes the trail before
 * the row (art. 8). The policy decided who may press; this decides what the
 * press may still do.
 *
 * @see D-127 · BR-15, BR-16 · CONSTITUTION Art. 7, Art. 8
 */
final class GuidePublication
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FinalProjectNotices $notices,
    ) {}

    public function setAvailability(FinalProjectGuide $guide, bool $available, User $admin): PublicationOutcome
    {
        $outcome = DB::transaction(function () use ($guide, $available, $admin): PublicationOutcome {
            $locked = $this->lock($guide);

            if ((bool) $locked->is_available === $available) {
                return PublicationOutcome::Unchanged;
            }

            if ($available && ! FinalProjectGuideVersion::query()->where('final_project_guide_id', $locked->getKey())->exists()) {
                return PublicationOutcome::NoContent;
            }

            $before = $this->state($locked);

            $locked->setAttribute('is_available', $available);
            $locked->setAttribute('available_at', $available ? Clock::now() : null);
            $locked->setAttribute('available_by', $available ? $admin->getKey() : null);

            if (! $available) {
                $locked->setAttribute('is_published', false);
                $locked->setAttribute('published_at', null);
                $locked->setAttribute('published_by', null);
            }

            $this->audit->log(
                action: $available ? 'final_project_guide.made_available' : 'final_project_guide.availability_withdrawn',
                entity: $locked,
                before: $before,
                after: $this->state($locked),
                actor: $admin,
            );

            $locked->save();

            if (! $available) {
                $this->takeEnglishDown($locked, $admin);
            }

            return PublicationOutcome::Changed;
        });

        $guide->refresh();

        if ($outcome === PublicationOutcome::Changed && $available) {
            $this->notices->guideAvailable($guide, $this->project($guide));
        }

        return $outcome;
    }

    public function publish(FinalProjectGuide $guide, User $coordinator): PublicationOutcome
    {
        $tellTrainers = false;
        $tellTrainees = false;

        $outcome = DB::transaction(function () use ($guide, $coordinator, &$tellTrainers, &$tellTrainees): PublicationOutcome {
            // The project first: whether the trainees are told now depends on
            // it being open, and it must not close between the read and the
            // announcement.
            /** @var FinalProject $project */
            $project = FinalProject::query()->whereKey((string) $guide->final_project_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lock($guide);

            if ((bool) $locked->is_published) {
                return PublicationOutcome::Unchanged;
            }

            if (! (bool) $locked->is_available) {
                return PublicationOutcome::NotAvailable;
            }

            if (! $locked->isPrimaryLocale() && ! $this->primaryPublished($project)) {
                return PublicationOutcome::NeedsPrimaryLocale;
            }

            $before = $this->state($locked);
            $now = Clock::now();

            $locked->setAttribute('is_published', true);
            $locked->setAttribute('published_at', $now);
            $locked->setAttribute('published_by', $coordinator->getKey());

            // Only the Arabic page announces the guide; English is a language
            // of it, not news (D-127).
            if ($locked->isPrimaryLocale()) {
                if ($locked->staff_announced_at === null) {
                    $locked->setAttribute('staff_announced_at', $now);
                    $tellTrainers = true;
                }

                // BR-15: the trainees hear of the guide only once they can
                // open the project it belongs to.
                if ($locked->announced_at === null && (bool) $project->is_unlocked) {
                    $locked->setAttribute('announced_at', $now);
                    $tellTrainees = true;
                }
            }

            $this->audit->log(
                action: 'final_project_guide.published',
                entity: $locked,
                before: $before,
                after: $this->state($locked) + ['announces_to_trainees' => $tellTrainees],
                actor: $coordinator,
            );

            $locked->save();

            return PublicationOutcome::Changed;
        });

        $guide->refresh();

        if ($outcome === PublicationOutcome::Changed) {
            $project = $this->project($guide);

            if ($tellTrainers) {
                $this->notices->guideToTrainers($project);
            }

            if ($tellTrainees) {
                $this->notices->guideToTrainees($project);
            }
        }

        return $outcome;
    }

    public function unpublish(FinalProjectGuide $guide, User $coordinator): PublicationOutcome
    {
        $outcome = DB::transaction(function () use ($guide, $coordinator): PublicationOutcome {
            $locked = $this->lock($guide);

            if (! (bool) $locked->is_published) {
                return PublicationOutcome::Unchanged;
            }

            $before = $this->state($locked);

            $locked->setAttribute('is_published', false);
            $locked->setAttribute('published_at', null);
            $locked->setAttribute('published_by', null);

            $this->audit->log(
                action: 'final_project_guide.unpublished',
                entity: $locked,
                before: $before,
                after: $this->state($locked),
                actor: $coordinator,
            );

            $locked->save();

            $this->takeEnglishDown($locked, $coordinator);

            return PublicationOutcome::Changed;
        });

        $guide->refresh();

        return $outcome;
    }

    /**
     * Arabic went down, so English goes down with it (D-127: Arabic is the
     * condition) — its own trail entry, the same actor.
     */
    private function takeEnglishDown(FinalProjectGuide $arabic, User $actor): void
    {
        if (! $arabic->isPrimaryLocale()) {
            return;
        }

        $english = FinalProjectGuide::query()
            ->where('final_project_id', $arabic->final_project_id)
            ->where('locale', '!=', FinalProjectGuide::PRIMARY_LOCALE)
            ->where('is_published', true)
            ->lockForUpdate()
            ->get();

        foreach ($english as $page) {
            $before = $this->state($page);

            $page->setAttribute('is_published', false);
            $page->setAttribute('published_at', null);
            $page->setAttribute('published_by', null);

            $this->audit->log(
                action: 'final_project_guide.unpublished',
                entity: $page,
                before: $before,
                after: $this->state($page) + ['with_primary_locale' => true],
                actor: $actor,
            );

            $page->save();
        }
    }

    private function primaryPublished(FinalProject $project): bool
    {
        return FinalProjectGuide::query()
            ->where('final_project_id', $project->getKey())
            ->where('locale', FinalProjectGuide::PRIMARY_LOCALE)
            ->where('is_published', true)
            ->exists();
    }

    private function lock(FinalProjectGuide $guide): FinalProjectGuide
    {
        /** @var FinalProjectGuide $locked */
        $locked = FinalProjectGuide::query()->whereKey($guide->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function project(FinalProjectGuide $guide): FinalProject
    {
        /** @var FinalProject $project */
        $project = FinalProject::query()->findOrFail((string) $guide->final_project_id);

        return $project;
    }

    /** @return array<string, mixed> */
    private function state(FinalProjectGuide $guide): array
    {
        return $this->audit->snapshot($guide, ['locale', 'is_available', 'available_by', 'is_published', 'published_by']);
    }
}
