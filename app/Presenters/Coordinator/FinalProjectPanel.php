<?php

declare(strict_types=1);

namespace App\Presenters\Coordinator;

use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\Dates;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The coordinator's final-project tab (D-127): the project's summary, and the
 * two publishing controls — the project, and its guide per language — which
 * only the cohort's primary coordinator may press.
 *
 * Whether a button shows is asked of the SAME policy that guards its route
 * (`publish`, `unpublish`, `view`), so the screen can never offer a press the
 * server would refuse — and hiding it is still not the control (Article 5).
 *
 * @see D-127 · D-105, D-124 · CONSTITUTION Art. 5, Art. 6, Art. 17
 */
final class FinalProjectPanel extends ViewModel
{
    use PresentsFormValues;

    /**
     * @param  Collection<int, FinalProjectGuide>  $guides  this project's rows, `currentVersion` loaded
     */
    public static function from(
        FinalProject $project,
        Collection $guides,
        User $viewer,
        bool $isPrimary,
        bool $hasPrimary,
        int $handIns,
        bool $confirmingUnpublish,
    ): self {
        $cohortId = (string) $project->cohort_id;
        $byLocale = $guides->keyBy('locale');
        $isAvailable = (bool) $project->is_available;
        $isPublished = (bool) $project->is_unlocked;
        /** @var FinalProjectGuide|null $arabic */
        $arabic = $byLocale->get(FinalProjectGuide::PRIMARY_LOCALE);
        $arabicPublished = (bool) $arabic?->is_published;

        $languages = [];

        foreach (FinalProjectGuide::LOCALES as $locale) {
            /** @var FinalProjectGuide|null $guide */
            $guide = $byLocale->get($locale);
            $hasContent = $guide?->currentVersion instanceof FinalProjectGuideVersion;

            $languages[] = [
                'locale' => $locale,
                'label' => (string) __('project.guide.languages.'.$locale),
                'stateLabel' => match (true) {
                    $guide === null || ! $guide->is_available => (string) __('coordinator.final_project.guide_states.not_available'),
                    (bool) $guide->is_published => (string) __('coordinator.final_project.guide_states.published'),
                    default => (string) __('coordinator.final_project.guide_states.available'),
                },
                'stateVariant' => match (true) {
                    $guide === null || ! $guide->is_available => 'muted',
                    (bool) $guide->is_published => 'success',
                    default => 'info',
                },
                'canView' => $guide !== null && $hasContent && $viewer->can('view', $guide),
                'viewUrl' => route('finalProjectGuide.show', ['project' => $project->getKey(), 'lang' => $locale]),
                // The screen offers the one press that would change something;
                // the service re-checks every condition under a lock anyway.
                'canPublish' => $guide !== null
                    && (bool) $guide->is_available
                    && ! (bool) $guide->is_published
                    && ($locale === FinalProjectGuide::PRIMARY_LOCALE || $arabicPublished)
                    && $viewer->can('publish', $guide),
                'canUnpublish' => $guide !== null && (bool) $guide->is_published && $viewer->can('unpublish', $guide),
                'action' => route('coordinator.finalProject.guide.publication', [$project->getKey(), $locale]),
                'waitsForPrimary' => $isPrimary
                    && $locale !== FinalProjectGuide::PRIMARY_LOCALE
                    && $guide !== null
                    && (bool) $guide->is_available
                    && ! (bool) $guide->is_published
                    && ! $arabicPublished,
            ];
        }

        return new self([
            'projectId' => (string) $project->getKey(),
            'cohortId' => $cohortId,
            'title' => (string) $project->title,
            'dueLabel' => Dates::dateTime($project->due_at),
            'isAvailable' => $isAvailable,
            'isPublished' => $isPublished,
            'stateLabel' => match (true) {
                ! $isAvailable => (string) __('coordinator.final_project.states.not_available'),
                $isPublished => (string) __('coordinator.final_project.states.published'),
                default => (string) __('coordinator.final_project.states.available'),
            },
            'stateVariant' => match (true) {
                ! $isAvailable => 'muted',
                $isPublished => 'success',
                default => 'info',
            },
            'publishedNote' => $isPublished && $project->unlocked_at !== null
                ? (string) __('coordinator.final_project.published_note', [
                    'name' => self::nameOf($project, 'unlocker'),
                    'date' => Dates::dateTime($project->unlocked_at),
                ])
                : null,
            'isPrimary' => $isPrimary,
            'hasPrimary' => $hasPrimary,
            'canPublish' => $isAvailable && ! $isPublished && $viewer->can('publish', $project),
            'canUnpublish' => $isPublished && $viewer->can('unpublish', $project),
            'publicationAction' => route('coordinator.finalProject.publication', $project->getKey()),
            'handInCount' => $handIns,
            'handInLabel' => trans_choice('coordinator.final_project.hand_ins', $handIns, ['count' => $handIns]),
            'confirmingUnpublish' => $confirmingUnpublish && $handIns > 0 && $viewer->can('unpublish', $project),
            'confirmUrl' => route('coordinator.finalProject', ['cohort' => $cohortId, 'confirm' => 'unpublish']).'#publish-project',
            'cancelUrl' => route('coordinator.finalProject', ['cohort' => $cohortId]).'#publish-project',
            'languages' => $languages,
        ]);
    }

    private static function nameOf(FinalProject $project, string $relation): string
    {
        $user = self::related($project, $relation);
        $profile = self::related($user, 'profile');
        $name = $profile === null ? self::attr($user, 'email') : self::attr($profile, 'full_name_ar');

        return is_string($name) ? $name : '';
    }
}
