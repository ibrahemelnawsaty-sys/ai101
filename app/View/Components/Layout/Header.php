<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

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
    /** Above this the badge reads "99+" rather than a four-digit number. */
    public const BADGE_CAP = 99;

    public string $heading;

    public string $sub;

    public int $unreadCount;

    public string $unreadLabel;

    public string $displayName;

    public string $email;

    public string $initials;

    public ?string $notificationsUrl;

    public ?string $messagesUrl;

    public bool $showMessages;

    public ?string $profileUrl;

    public ?string $cardUrl;

    public ?string $logoutUrl;

    /** @var array<string, int> */
    public array $badges;

    /**
     * @param  array<string, int>  $badges
     */
    public function __construct(
        string $title = '',
        string $subtitle = '',
        mixed $badges = [],
        mixed $unread = 0,
    ) {
        $this->badges = is_array($badges) ? $badges : [];
        $this->heading = trim($title);
        $this->sub = trim($subtitle);

        $this->unreadCount = max(0, (int) $unread);
        $this->unreadLabel = $this->unreadCount > self::BADGE_CAP ? self::BADGE_CAP.'+' : (string) $this->unreadCount;

        // loadMissing is an explicit eager load, so it stays legal under
        // Model::preventLazyLoading() outside production (art. 19).
        $user = auth()->user()?->loadMissing('profile');

        $this->displayName = trim((string) (
            data_get($user, 'profile.short_name_ar')
            ?: data_get($user, 'profile.full_name_ar')
            ?: data_get($user, 'email', '')
        ));
        $this->email = (string) data_get($user, 'email', '');
        $this->initials = self::initialsOf($this->displayName);

        $this->notificationsUrl = Route::has('notifications') ? route('notifications') : null;
        $this->messagesUrl = Route::has('messages.index') ? route('messages.index') : null;
        $this->showMessages = $this->messagesUrl !== null && (int) ($this->badges['messages'] ?? 0) > 0;
        $this->profileUrl = Route::has('profile') ? route('profile') : null;
        $this->cardUrl = Route::has('participant.card') ? route('participant.card') : null;
        $this->logoutUrl = Route::has('logout') ? route('logout') : null;
    }

    /**
     * Two initials for the avatar fallback, taken from whole words and never by
     * slicing inside one: Arabic letters connect, and cutting a word apart
     * breaks its shape (CONSTITUTION art. 16-bis). The first letter of a word is
     * a safe, standalone grapheme.
     */
    private static function initialsOf(string $name): string
    {
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($words)
            ->take(2)
            ->map(static fn (string $word): string => mb_substr($word, 0, 1, 'UTF-8'))
            ->implode('');
    }

    public function render(): View
    {
        return view('components.layout.header');
    }
}
