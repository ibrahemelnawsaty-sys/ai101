<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\Dates;
use App\Support\ViewModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The guide card on the general supervisor's final-project screen (D-127):
 * each language's state, its preview, its availability switch, and — on
 * `?guide=ar|en` — the editor with the page on show and every earlier version.
 *
 * Every figure and every flag is decided here; the view prints them.
 *
 * @see D-127 · CONSTITUTION Art. 5, Art. 6, Art. 17
 */
final class FinalProjectGuidePanel extends ViewModel
{
    use PresentsFormValues;

    /**
     * @param  Collection<int, FinalProjectGuide>  $guides  this project's rows, `currentVersion` loaded
     * @param  LengthAwarePaginator<int, FinalProjectGuideVersion>|null  $history  the edited language's versions, newest first
     * @param  list<array<string, string>>  $copyOptions  other cohorts with a saved guide, as select options
     */
    public static function from(
        FinalProject $project,
        Collection $guides,
        ?string $editing,
        ?LengthAwarePaginator $history,
        bool $hasPrimaryCoordinator,
        array $copyOptions,
    ): self {
        $cohortId = (string) $project->cohort_id;
        $byLocale = $guides->keyBy('locale');

        $languages = [];

        foreach (FinalProjectGuide::LOCALES as $locale) {
            /** @var FinalProjectGuide|null $guide */
            $guide = $byLocale->get($locale);
            $current = $guide?->currentVersion;

            $languages[] = [
                'locale' => $locale,
                'label' => (string) __('project.guide.languages.'.$locale),
                'hasContent' => $current instanceof FinalProjectGuideVersion,
                'versionLabel' => $current instanceof FinalProjectGuideVersion
                    ? (string) __('admin.final_project.guide.version_label', [
                        'number' => $current->version,
                        'date' => Dates::dateTime($current->created_at),
                    ])
                    : (string) __('admin.final_project.guide.no_content'),
                'isAvailable' => (bool) $guide?->is_available,
                'isPublished' => (bool) $guide?->is_published,
                'stateLabel' => self::stateLabel($guide),
                'stateVariant' => self::stateVariant($guide),
                'editUrl' => route('admin.finalProject.index', ['cohort' => $cohortId, 'guide' => $locale]).'#guide-editor',
                'previewUrl' => $current instanceof FinalProjectGuideVersion
                    ? route('finalProjectGuide.show', ['project' => $project->getKey(), 'lang' => $locale])
                    : null,
                'availabilityAction' => route('admin.finalProject.guide.availability', [$project->getKey(), $locale]),
            ];
        }

        return new self([
            'projectId' => (string) $project->getKey(),
            'cohortId' => $cohortId,
            'languages' => $languages,
            'hasPrimaryCoordinator' => $hasPrimaryCoordinator,
            'copyOptions' => $copyOptions,
            'copyAction' => route('admin.finalProject.guide.copy', $project->getKey()),
            'editor' => $editing === null ? null : self::editor($project, $byLocale->get($editing), $editing, $history),
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, FinalProjectGuideVersion>|null  $history
     * @return array<string, mixed>
     */
    private static function editor(FinalProject $project, ?FinalProjectGuide $guide, string $locale, ?LengthAwarePaginator $history): array
    {
        $current = $guide?->currentVersion;
        $versions = new Collection($history?->items() ?? []);

        return [
            'locale' => $locale,
            'title' => (string) __('admin.final_project.guide.editor_title', ['language' => __('project.guide.languages.'.$locale)]),
            'html' => $current instanceof FinalProjectGuideVersion ? (string) $current->html : '',
            'direction' => 'ltr',
            'saveAction' => route('admin.finalProject.guide.save', [$project->getKey(), $locale]),
            'restoreAction' => route('admin.finalProject.guide.restore', [$project->getKey(), $locale]),
            'closeUrl' => route('admin.finalProject.index', ['cohort' => $project->cohort_id]).'#guide',
            'history' => $history,
            'versions' => $versions->map(static fn (FinalProjectGuideVersion $version): array => [
                'number' => $version->version,
                'isCurrent' => $current instanceof FinalProjectGuideVersion && $version->is($current),
                'label' => (string) __('admin.final_project.guide.version_label', [
                    'number' => $version->version,
                    'date' => Dates::dateTime($version->created_at),
                ]),
                'author' => self::authorName($version),
                'sourceLabel' => (string) __('admin.final_project.guide.sources.'.$version->source, [
                    'number' => (string) $version->restored_from,
                ]),
                'size' => (string) __('admin.final_project.guide.size_kb', ['size' => max(1, (int) ceil($version->bytes / 1024))]),
            ])->values()->all(),
        ];
    }

    private static function stateLabel(?FinalProjectGuide $guide): string
    {
        return match (true) {
            $guide === null || ! $guide->is_available => (string) __('admin.final_project.guide.states.not_available'),
            (bool) $guide->is_published => (string) __('admin.final_project.guide.states.published'),
            default => (string) __('admin.final_project.guide.states.available'),
        };
    }

    private static function stateVariant(?FinalProjectGuide $guide): string
    {
        return match (true) {
            $guide === null || ! $guide->is_available => 'muted',
            (bool) $guide->is_published => 'success',
            default => 'info',
        };
    }

    private static function authorName(FinalProjectGuideVersion $version): string
    {
        $author = self::related($version, 'author');
        $profile = self::related($author, 'profile');

        $name = $profile === null ? self::attr($author, 'email') : self::attr($profile, 'full_name_ar');

        return is_string($name) && $name !== '' ? $name : (string) __('admin.final_project.guide.author_system');
    }
}
