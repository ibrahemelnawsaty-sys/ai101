<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

use App\Enums\UserRole;
use App\View\Components\Layout\Concerns\ResolvesCurrentUser;
use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

/**
 * Dashboard app bar view model.
 *
 * The bar is chrome, not authorisation. The notification count it shows and the
 * links it offers reflect what the server already decided; every target route
 * re-checks the session, the role and the resource scope on its own
 * (CONSTITUTION art. 5).
 *
 * @see PRD §9.5.2 · D-86, D-108, D-123 · CONSTITUTION.md Articles 5, 13, 16, 17, 18, 23
 */
final class Header extends UiComponent
{
    use ResolvesCurrentUser;

    /** Above this the badge reads "99+" rather than a four-digit number. */
    public const BADGE_CAP = 99;

    public string $heading;

    public string $sub;

    public int $unreadCount;

    public string $unreadLabel;

    public ?string $notificationsUrl;

    public ?string $messagesUrl;

    public bool $showMessages;

    /**
     * D-123 — the account menu's links, in the order the owner fixed. Sign-out
     * is not among them: it is a POST form, drawn after the separator.
     *
     * @var list<array{key: string, url: string, icon: string, label: string, current: bool}>
     */
    public array $menuItems;

    /** @var array<string, int> */
    public array $badges;

    /**
     * `$badges` and `$unread` stay `mixed`: Blade passes a template attribute
     * through untouched, so `badges="3"` arrives as a string. counts() is what
     * turns whatever came in into the array<string, int> the bar reads.
     */
    public function __construct(
        string $title = '',
        string $subtitle = '',
        mixed $badges = [],
        mixed $unread = 0,
    ) {
        $this->badges = self::counts($badges);
        $this->heading = trim($title);
        $this->sub = trim($subtitle);

        $this->unreadCount = max(0, (int) $unread);
        $this->unreadLabel = $this->unreadCount > self::BADGE_CAP ? self::BADGE_CAP.'+' : (string) $this->unreadCount;

        $this->resolveCurrentUser();

        $this->notificationsUrl = Route::has('notifications') ? route('notifications') : null;
        $this->messagesUrl = Route::has('messages.index') ? route('messages.index') : null;
        $this->showMessages = $this->messagesUrl !== null && (int) ($this->badges['messages'] ?? 0) > 0;
        $this->menuItems = $this->accountMenu();
    }

    public function render(): View
    {
        return view('components.layout.header');
    }

    /**
     * What the account menu offers, and to whom (D-123). A link is offered only
     * when its route is registered and this account's role reaches it: a link
     * the server refuses is a link to a 403 (D-86). The entries whose feature
     * is not built yet — support tickets (D-124), the tour, English, dark mode —
     * are absent rather than present and dead (D-54, D-66).
     *
     * @return list<array{key: string, url: string, icon: string, label: string, current: bool}>
     */
    private function accountMenu(): array
    {
        $items = [];

        // The card belongs to trainees: the route sits behind role:participant.
        // A preview signs in AS the trainee, so it still shows there.
        if ($this->role === UserRole::Participant) {
            $items[] = self::menuItem('card', 'participant.card', 'card', __('nav.participant.card'), ['participant.card']);
        }

        // The system administrator has no information dashboard: /dashboard
        // sends that role to the accounts list (D-117), so the label would lie.
        // Every other role reaches its own dashboard through the one route.
        if ($this->role !== null && $this->role !== UserRole::SystemAdmin) {
            $items[] = self::menuItem('dashboard', 'dashboard', 'panel', __('nav.dashboard'), [
                'dashboard', 'trainer.dashboard', 'coordinator.dashboard', 'admin.dashboard',
            ]);
        }

        $items[] = self::menuItem('profile', 'profile', 'user', __('nav.chrome.account'), ['profile']);

        return array_values(array_filter($items));
    }

    /**
     * One entry, or null when the router has not registered its route: the
     * chrome must never take a whole screen down (CONSTITUTION art. 7). The
     * icon is the bare sprite name; <x-ui.icon> alone adds the prefix
     * (PROJECT-CONTRACT §17).
     *
     * @param  list<string>  $currentOn  the route names on which this entry is the current page
     * @return array{key: string, url: string, icon: string, label: string, current: bool}|null
     */
    private static function menuItem(string $key, string $route, string $icon, string $label, array $currentOn): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        return [
            'key' => $key,
            'url' => route($route),
            'icon' => $icon,
            'label' => $label,
            'current' => request()->routeIs(...$currentOn),
        ];
    }
}
