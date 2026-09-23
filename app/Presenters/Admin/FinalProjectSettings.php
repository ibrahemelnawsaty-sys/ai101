<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\FinalProject;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * The final-project settings form, on the administrator's own screen (D-110).
 *
 * `dueAtValue`/`requirementsLines` are wall-clock/one-per-line strings ready
 * for their form controls — the same round trip every other admin settings
 * screen uses (PresentsFormValues).
 *
 * @see D-109, D-110 · PRD §9.14 · CONSTITUTION art. 11
 */
final class FinalProjectSettings extends ViewModel
{
    use PresentsFormValues;

    public static function blank(string $cohortId): self
    {
        return new self([
            'exists' => false,
            'id' => null,
            'cohortId' => $cohortId,
            'title' => '',
            'brief' => '',
            'requirementsLines' => '',
            'dueAtValue' => '',
            'maxScore' => '100',
            'allowLate' => false,
            'isUnlocked' => false,
            'unlockedAt' => null,
            'unlockedByName' => null,
        ]);
    }

    public static function from(FinalProject $project): self
    {
        $unlocker = self::related($project, 'unlocker');
        $unlockerProfile = self::related($unlocker, 'profile');

        return new self([
            'exists' => true,
            'id' => (string) $project->getKey(),
            'cohortId' => (string) $project->getAttribute('cohort_id'),
            'title' => (string) $project->getAttribute('title'),
            'brief' => (string) $project->getAttribute('brief'),
            'requirementsLines' => self::linesFrom($project->getAttribute('requirements')),
            'dueAtValue' => self::dateTimeInput($project->getAttribute('due_at')),
            'maxScore' => (string) (int) ($project->getAttribute('max_score') ?? 100),
            'allowLate' => (bool) $project->getAttribute('allow_late'),
            'isUnlocked' => (bool) $project->getAttribute('is_unlocked'),
            'unlockedAt' => $project->getAttribute('unlocked_at'),
            'unlockedByName' => $unlockerProfile === null
                ? self::attr($unlocker, 'email')
                : self::attr($unlockerProfile, 'full_name_ar'),
        ]);
    }
}
