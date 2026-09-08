<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

use App\Models\User;
use App\Services\Permissions\RoleResolver;
use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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
 * NOTE ON TRAINER / ADMIN GROUPING - D-30, resolved 8 September 2026
 * The PRD specifies the participant menu only, so this component used to fall
 * back to the participant rail for every role and wait for a controller to hand
 * it `:groups`. NO CONTROLLER EVER DID. The consequence was found on the live
 * host, not in review: an administrator signed in, was served the PARTICIPANT
 * rail, and had no link to programmes, cohorts, users, registrations,
 * certificates, the audit log or settings. Every one of those screens existed
 * and worked - the road to them was simply missing.
 *
 * Leaving D-30 open cost more than choosing would have: an unreachable admin
 * console is a worse answer than a rail that can be rearranged later. So the
 * rail is chosen HERE, from the signed-in user's role, with the SAME precedence
 * DashboardController already applies - admin first, then a trainer who is not
 * also a participant, else participant. Two different answers to "which role am
 * I right now" would be worse than either answer alone.
 *
 * A controller may still pass `:groups` and it still wins, so nothing that
 * relied on the previous contract breaks.
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
     * The props stay `mixed`: Blade passes a template attribute through
     * untouched, so `drawer="1"` arrives as a string and a controller may hand
     * over a ragged array. rows() and counts() are the boundary that turns what
     * came in into the shapes the rail declares.
     *
     * `$groups` keeps its own is_array guard: anything that is not an array
     * means "use the participant rail", which is not the same answer as an
     * empty rail.
     */
    public function __construct(
        mixed $groups = null,
        mixed $badges = [],
        public ?string $cohort = null,
        mixed $cohorts = [],
        mixed $drawer = false,
    ) {
        $this->badges = self::counts($badges);
        $this->cohorts = self::rows($cohorts);
        $this->drawer = (bool) $drawer;
        $this->resolvedGroups = $this->resolveGroups(is_array($groups) ? self::rows($groups) : $this->defaultGroups());

        $this->tag = $this->drawer ? 'div' : 'aside';
        $this->wrapperClass = $this->drawer ? 'drawer__panel' : 'side';

        // POST /dashboard/cohort -> cohort.switch, PROJECT-CONTRACT §10. The
        // guard stays: the switcher is pointless with a single cohort.
        $this->switchUrl = Route::has('cohort.switch') ? route('cohort.switch') : null;
        $this->showSwitcher = count($this->cohorts) > 1 && $this->switchUrl !== null;
    }

    /**
     * The rail for whoever is signed in.
     *
     * A signed-out render falls back to the participant rail. There is none
     * today, but the component must not depend on that, and every route it
     * links to would bounce a guest to the login screen anyway.
     *
     * @return list<array{label?: string, items: list<array<string, mixed>>}>
     */
    private function defaultGroups(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return $this->participantGroups();
        }

        $roles = app(RoleResolver::class);

        if ($roles->isAdmin($user)) {
            return $this->adminGroups();
        }

        if (! $roles->hasRole($user, 'participant') && $roles->hasRole($user, 'trainer')) {
            return $this->trainerGroups();
        }

        return $this->participantGroups();
    }

    /**
     * The administrator console rail.
     *
     * Grouped by what the work IS rather than by the URL tree: what the
     * programme is, who the people are, what the platform produced, and how it
     * is configured. resolveGroups() drops any item whose route is not
     * registered, so this degrades to whatever the router actually has instead
     * of throwing in the chrome.
     *
     * @return list<array{label?: string, items: list<array<string, mixed>>}>
     */
    private function adminGroups(): array
    {
        return [
            ['items' => [
                ['route' => 'admin.dashboard', 'icon' => 'i-home', 'label' => __('nav.admin.dashboard')],
            ]],
            ['label' => __('nav.groups.program'), 'items' => [
                ['route' => 'admin.programs.index', 'icon' => 'i-spark', 'label' => __('nav.admin.programs')],
                ['route' => 'admin.cohorts.index', 'icon' => 'i-cal', 'label' => __('nav.admin.cohorts')],
                ['route' => 'admin.landing.edit', 'icon' => 'i-globe', 'label' => __('nav.admin.landing')],
            ]],
            ['label' => __('nav.groups.admin'), 'items' => [
                ['route' => 'admin.users.index', 'icon' => 'i-users', 'label' => __('nav.admin.users')],
                ['route' => 'admin.registrations.index', 'icon' => 'i-user', 'label' => __('nav.admin.registrations')],
                ['route' => 'admin.certificates.index', 'icon' => 'i-badge', 'label' => __('nav.admin.certificates')],
            ]],
            ['label' => __('nav.groups.work'), 'items' => [
                ['route' => 'admin.reports.index', 'icon' => 'i-chart', 'label' => __('nav.admin.reports')],
                ['route' => 'admin.audit.index', 'icon' => 'i-shield', 'label' => __('nav.admin.audit')],
                ['route' => 'admin.settings.edit', 'icon' => 'i-lock', 'label' => __('nav.admin.settings')],
            ]],
        ];
    }

    /**
     * The trainer rail, ordered by the rhythm of a training week: who is in the
     * cohort, when it meets, what was set, what came back, what the numbers say.
     *
     * @return list<array{label?: string, items: list<array<string, mixed>>}>
     */
    private function trainerGroups(): array
    {
        return [
            ['items' => [
                ['route' => 'trainer.participants', 'icon' => 'i-users', 'label' => __('nav.trainer.participants')],
            ]],
            ['label' => __('nav.groups.program'), 'items' => [
                ['route' => 'trainer.sessions', 'icon' => 'i-cal', 'label' => __('nav.trainer.sessions')],
                ['route' => 'trainer.attendance', 'icon' => 'i-check', 'label' => __('nav.trainer.attendance')],
                ['route' => 'trainer.resources', 'icon' => 'i-folder', 'label' => __('nav.trainer.resources')],
            ]],
            ['label' => __('nav.groups.work'), 'items' => [
                ['route' => 'trainer.assignments', 'icon' => 'i-file', 'label' => __('nav.trainer.assignments')],
                ['route' => 'trainer.submissions', 'icon' => 'i-check', 'label' => __('nav.trainer.submissions')],
                ['route' => 'trainer.finalProject', 'icon' => 'i-spark', 'label' => __('nav.trainer.final_project')],
                ['route' => 'trainer.reports', 'icon' => 'i-chart', 'label' => __('nav.trainer.reports')],
            ]],
        ];
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
