<?php

declare(strict_types=1);

namespace App\Services\FinalProject;

use App\Enums\PublicationOutcome;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The final project's two steps (D-127): the general supervisor makes it
 * available, the cohort's primary coordinator publishes it.
 *
 * Publishing is `is_unlocked` — the one column BR-15/BR-16 lock on — so every
 * place that already hides a locked project keeps doing so untouched. What
 * this adds is the order: nothing opens that is not available, and withdrawing
 * availability locks the project in the same write.
 *
 * Every change re-reads the row under a lock, writes the trail BEFORE the row
 * (art. 8), and does nothing when the state already is what was asked. The
 * policy decided WHO may press; this decides what the press may still do now.
 *
 * @see D-127 · BR-15, BR-16 · D-77 · CONSTITUTION Art. 7, Art. 8
 */
final class ProjectPublication
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FinalProjectNotices $notices,
    ) {}

    public function setAvailability(FinalProject $project, bool $available, User $admin): PublicationOutcome
    {
        $outcome = DB::transaction(function () use ($project, $available, $admin): PublicationOutcome {
            $locked = $this->lock($project);

            if ((bool) $locked->is_available === $available) {
                return PublicationOutcome::Unchanged;
            }

            $before = $this->state($locked);
            $now = Clock::now();

            $locked->setAttribute('is_available', $available);
            $locked->setAttribute('available_at', $available ? $now : null);
            $locked->setAttribute('available_by', $available ? $admin->getKey() : null);

            // Withdrawing availability takes a published project down with it.
            if (! $available) {
                $locked->setAttribute('is_unlocked', false);
                $locked->setAttribute('unlocked_at', null);
                $locked->setAttribute('unlocked_by', null);
            }

            $this->audit->log(
                action: $available ? 'final_project.made_available' : 'final_project.availability_withdrawn',
                entity: $locked,
                before: $before,
                after: $this->state($locked),
                actor: $admin,
            );

            $locked->save();

            return PublicationOutcome::Changed;
        });

        $project->refresh();

        if ($outcome === PublicationOutcome::Changed && $available) {
            $this->notices->projectAvailable($project);
        }

        return $outcome;
    }

    public function publish(FinalProject $project, User $coordinator): PublicationOutcome
    {
        $withGuide = false;

        $outcome = DB::transaction(function () use ($project, $coordinator, &$withGuide): PublicationOutcome {
            $locked = $this->lock($project);

            if ((bool) $locked->is_unlocked) {
                return PublicationOutcome::Unchanged;
            }

            if (! (bool) $locked->is_available) {
                return PublicationOutcome::NotAvailable;
            }

            $before = $this->state($locked);
            $now = Clock::now();

            $locked->setAttribute('is_unlocked', true);
            $locked->setAttribute('unlocked_at', $now);
            $locked->setAttribute('unlocked_by', $coordinator->getKey());

            // A guide published while the project was still locked was never
            // announced to the trainees; it goes out with the project now, as
            // one notice rather than two (D-127).
            $guide = FinalProjectGuide::query()
                ->where('final_project_id', $locked->getKey())
                ->where('locale', FinalProjectGuide::PRIMARY_LOCALE)
                ->where('is_published', true)
                ->whereNull('announced_at')
                ->lockForUpdate()
                ->first();

            $withGuide = $guide instanceof FinalProjectGuide;

            $this->audit->log(
                action: 'final_project.published',
                entity: $locked,
                before: $before,
                after: $this->state($locked) + ['announces_guide' => $withGuide],
                actor: $coordinator,
            );

            $locked->save();

            if ($guide instanceof FinalProjectGuide) {
                $guide->setAttribute('announced_at', $now);
                $guide->save();
            }

            return PublicationOutcome::Changed;
        });

        $project->refresh();

        if ($outcome === PublicationOutcome::Changed) {
            $this->notices->projectPublished($project, $withGuide);
        }

        return $outcome;
    }

    /** Taking the project down again. What was handed in stays where it is. */
    public function unpublish(FinalProject $project, User $coordinator): PublicationOutcome
    {
        $outcome = DB::transaction(function () use ($project, $coordinator): PublicationOutcome {
            $locked = $this->lock($project);

            if (! (bool) $locked->is_unlocked) {
                return PublicationOutcome::Unchanged;
            }

            $before = $this->state($locked);

            $locked->setAttribute('is_unlocked', false);
            $locked->setAttribute('unlocked_at', null);
            $locked->setAttribute('unlocked_by', null);

            $this->audit->log(
                action: 'final_project.unpublished',
                entity: $locked,
                before: $before,
                after: $this->state($locked),
                actor: $coordinator,
            );

            $locked->save();

            return PublicationOutcome::Changed;
        });

        $project->refresh();

        return $outcome;
    }

    private function lock(FinalProject $project): FinalProject
    {
        /** @var FinalProject $locked */
        $locked = FinalProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /** @return array<string, mixed> */
    private function state(FinalProject $project): array
    {
        return $this->audit->snapshot($project, ['is_available', 'available_by', 'is_unlocked', 'unlocked_by']);
    }
}
