<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

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
 * @see PRD §9.5.2 · CONSTITUTION.md Articles 5, 13, 16, 17, 18, 23
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

    public ?string $profileUrl;

    public ?string $cardUrl;

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
        $this->profileUrl = Route::has('profile') ? route('profile') : null;
        $this->cardUrl = Route::has('participant.card') ? route('participant.card') : null;
    }

    public function render(): View
    {
        return view('components.layout.header');
    }
}
