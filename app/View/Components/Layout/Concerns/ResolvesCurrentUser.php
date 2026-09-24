<?php

declare(strict_types=1);

namespace App\View\Components\Layout\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * The signed-in identity Header and Sidebar both show: a name, two initials,
 * a role badge and the logout link. One resolution shared by both, so the
 * header dropdown and the sidebar footer can never disagree on who is signed
 * in or what their role is (CONSTITUTION art. 6).
 *
 * The role badge names the account's own role column directly — the same
 * value RoleResolver::shellRole() reduces to for every reachable signed-in
 * request, since only an ACTIVE account ever renders a page at all. Colour
 * never carries this alone: the label text is the role name in Arabic, and
 * the badge variant is only ever one of the platform's existing brand pairs
 * — five since D-117 gave the system administrator its own, from the violet
 * scale in tokens.css — never a new colour (D-108, art. 18).
 *
 * @see D-108, D-117 · CONSTITUTION Art. 6, Art. 18
 */
trait ResolvesCurrentUser
{
    public string $displayName;

    public string $email;

    public string $initials;

    public ?UserRole $role;

    public string $roleLabel;

    public string $roleVariant;

    public ?string $logoutUrl;

    protected function resolveCurrentUser(): void
    {
        // loadMissing is an explicit eager load, so it stays legal under
        // Model::preventLazyLoading() outside production (art. 19).
        $user = Auth::user()?->loadMissing('profile');

        $this->displayName = trim((string) (
            data_get($user, 'profile.short_name_ar')
            ?: data_get($user, 'profile.full_name_ar')
            ?: data_get($user, 'email', '')
        ));
        $this->email = (string) data_get($user, 'email', '');
        $this->initials = self::initialsOf($this->displayName);

        $this->role = $user instanceof User ? $user->role : null;
        $this->roleLabel = $this->role?->label() ?? '';
        $this->roleVariant = $this->role?->value ?? 'neutral';

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
}
