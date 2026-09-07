<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Models\FinalProject;
use App\Presenters\Concerns\PresentsPeople;
use App\Presenters\Shared\FileLink;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The final-project brief on the trainer's own screen (PRD §9.14).
 *
 * BR-15 and BR-16: the tab opens for a whole cohort at once, and only a trainer
 * of that cohort or an administrator may open it. `isUnlocked` is what the
 * switch on the screen reflects — the switch is not the rule; the policy on
 * UnlockFinalProjectRequest is, and it answers a direct POST with 403 whether
 * or not the control was drawn (art. 5).
 *
 * `unlockedByName` and `unlockedAt` exist so the screen can say who opened it
 * and when, which is the same pair audit_logs records — a trainer should be
 * able to see the answer without reading the trail (art. 8).
 *
 * `isMissing` is true when the cohort has no final project row at all, which is
 * a genuine empty state rather than an error (art. 17).
 *
 * @see BR-15, BR-16, BR-23 · PRD §9.14 · CONSTITUTION art. 5, art. 8, art. 17
 */
final class FinalProjectBrief extends ViewModel
{
    use PresentsPeople;

    /** The cohort has no final project row yet. */
    public static function missing(): self
    {
        return new self([
            'isMissing' => true,
            'id' => null,
            'title' => '—',
            'description' => null,
            'requirements' => [],
            'attachments' => [],
            'dueAt' => null,
            'maxScore' => '0',
            'isUnlocked' => false,
            'unlockedAt' => null,
            'unlockedByName' => '—',
            'toggleValue' => '1',
        ]);
    }

    public static function from(FinalProject $project): self
    {
        $isUnlocked = (bool) $project->getAttribute('is_unlocked');
        $unlocker = self::related($project, 'unlocker');

        return new self([
            'isMissing' => false,
            'id' => (string) $project->getKey(),
            'title' => (string) $project->getAttribute('title'),
            // The brief lives in `final_projects.brief`; there is no
            // `description` column (PROJECT-CONTRACT §4).
            'description' => Present::text($project->getAttribute('brief')),
            'requirements' => Present::stringList($project->getAttribute('requirements')),
            'attachments' => FileLink::collection($project->getAttribute('attachments')),
            'dueAt' => $project->getAttribute('due_at'),
            'maxScore' => (string) (int) ($project->getAttribute('max_score') ?? 0),
            'isUnlocked' => $isUnlocked,
            'unlockedAt' => $project->getAttribute('unlocked_at'),
            'unlockedByName' => $unlocker === null ? '—' : self::personName($unlocker),
            // The switch posts the OPPOSITE of the current state: the button
            // says "open it" while it is shut and "close it" while it is open.
            'toggleValue' => $isUnlocked ? '0' : '1',
        ]);
    }
}
