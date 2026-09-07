<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar rail view model — pinned to the RIGHT edge of the screen.
 *
 * Grouping follows PRD §9.5.1 exactly for the participant:
 *   overview   (dashboard, digital card, my journey)
 *   programme  (schedule, attendance, live sessions)
 *   work       (assignments, resources, final project, grades)
 *   contact    (messages)
 *
 * NOTE ON AUTHORISATION
 * The visible menu is a reflection of permission, never its source. Every route
 * it links to enforces its own middleware, policy and query scope on the server
 * (CONSTITUTION art. 5). Hiding an item here protects nothing.
 *
 * NOTE ON TRAINER / ADMIN GROUPING
 * PRD §9.5.1 specifies the participant menu only, and PROJECT-CONTRACT §10
 * names /trainer/* and /admin/* as wildcards with no individual route names.
 * Rather than invent a structure (art. 4), those roles render the groups their
 * controller passes in. Escalated as D-30 in docs/03-decisions/DECISIONS.md.
 *
 * @see PRD §9.5.1 · CONSTITUTION.md Articles 4, 5, 7, 13, 16 · CONTRACT §10
 */
final class Sidebar extends UiComponent
{
    /** Above this the badge reads "99+" rather than a four-digit number. */
    public const BADGE_CAP = 99;

    /** @var list<array{label?: string, items: list<array<string, mixed>>}> */
    public array $resolvedGroups;

    public string $tag;

    public string $wrapperClass;

    public ?string $switchUrl;

    public bool $showSwitcher;

    /** @var array<string, int> */
    public array $badges;

    /** @var list<array<string, mixed>> */
    public array $cohorts;

    public bool $drawer;

    /**
     * @param  array<int, array<string, mixed>>|null  $groups
     * @param  array<string, int>  $badges
     * @param  array<int, array<string, mixed>>  $cohorts
     */
    public function __construct(
        mixed $groups = null,
        mixed $badges = [],
        public ?string $cohort = null,
        mixed $cohorts = [],
        mixed $drawer = false,
    ) {
        $this->badges = is_array($badges) ? $badges : [];
        $this->cohorts = is_array($cohorts) ? array_values($cohorts) : [];
        $this->drawer = (bool) $drawer;
        $this->resolvedGroups = $this->resolveGroups(is_array($groups) ? $groups : $this->participantGroups());

        $this->tag = $this->drawer ? 'div' : 'aside';
        $this->wrapperClass = $this->drawer ? 'drawer__panel' : 'side';

        // POST /dashboard/cohort -> cohort.switch, PROJECT-CONTRACT §10. The
        // guard stays: the switcher is pointless with a single cohort.
        $this->switchUrl = Route::has('cohort.switch') ? route('cohort.switch') : null;
        $this->showSwitcher = count($this->cohorts) > 1 && $this->switchUrl !== null;
    }

    /**
     * The participant rail, exactly as PRD §9.5.1 groups it.
     *
     * @return list<array{items: list<array<string, mixed>>}>
     */
    private function participantGroups(): array
    {
        return [
            ['items' => [
                ['route' => 'dashboard', 'icon' => 'i-home', 'label' => __('nav.participant.dashboard')],
                ['route' => 'participant.card', 'icon' => 'i-card', 'label' => __('nav.participant.card')],
                ['route' => 'participant.journey', 'icon' => 'i-route', 'label' => __('nav.participant.journey')],
            ]],
            ['items' => [
                ['route' => 'schedule', 'icon' => 'i-cal', 'label' => __('nav.participant.schedule')],
                ['route' => 'attendance.index', 'icon' => 'i-check', 'label' => __('nav.participant.attendance')],
                ['route' => 'live', 'icon' => 'i-video', 'label' => __('nav.participant.live')],
            ]],
            ['items' => [
                ['route' => 'assignments.index', 'icon' => 'i-file', 'label' => __('nav.participant.assignments'), 'badge' => 'assignments'],
                ['route' => 'resources.index', 'icon' => 'i-folder', 'label' => __('nav.participant.resources')],
                ['route' => 'finalProject', 'icon' => 'i-spark', 'label' => __('nav.participant.final_project')],
                ['route' => 'grades', 'icon' => 'i-chart', 'label' => __('nav.participant.grades')],
            ]],
            ['items' => [
                ['route' => 'messages.index', 'icon' => 'i-chat', 'label' => __('nav.participant.messages'), 'badge' => 'messages'],
            ]],
        ];
    }

    /**
     * Drop every item whose route does not exist yet, then drop the groups that
     * are left empty, and precompute what each surviving item needs so the
     * template's loop carries no logic of its own.
     *
     * An item whose route is not registered is dropped rather than thrown: fail
     * safe, never fail loud in the chrome (art. 7).
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return list<array{label?: string, items: list<array<string, mixed>>}>
     */
    private function resolveGroups(array $groups): array
    {
        $resolved = [];

        foreach ($groups as $group) {
            $items = [];

            foreach ($group['items'] ?? [] as $item) {
                $name = $item['route'] ?? '';

                if (! is_string($name) || ! Route::has($name)) {
                    continue;
                }

                $badgeKey = $item['badge'] ?? null;
                $count = is_string($badgeKey) ? (int) ($this->badges[$badgeKey] ?? 0) : 0;

                $item['href'] = route($name, $item['params'] ?? []);
                $item['active'] = request()->routeIs($name);
                $item['locked'] = (bool) ($item['locked'] ?? false);
                $item['badgeCount'] = $count;
                $item['badgeLabel'] = $count > self::BADGE_CAP ? self::BADGE_CAP.'+' : (string) $count;

                $items[] = $item;
            }

            if ($items !== []) {
                $group['items'] = $items;
                $resolved[] = $group;
            }
        }

        return $resolved;
    }

    public function render(): View
    {
        return view('components.layout.sidebar');
    }
}
