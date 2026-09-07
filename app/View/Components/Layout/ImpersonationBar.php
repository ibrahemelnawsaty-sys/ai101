<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

use App\Services\Time\Clock;
use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

/**
 * Preview-mode banner view model.
 *
 * WHAT THIS IS NOT
 * It is not the enforcement. Read-only is enforced in the data layer by
 * App\Http\Middleware\ImpersonationReadOnly, which rejects every unsafe method
 * whether or not this bar was ever rendered (art. 5, BR-33). Deleting this
 * class would change nothing about what a previewing administrator can do.
 *
 * The countdown is anchored to Clock::now(); the browser clock is never trusted
 * (BR-07). When it reaches zero the form is submitted so the SERVER ends the
 * session and says so - the browser does not decide it is over.
 *
 * The context arrives as the shared `impersonation` value the middleware sets:
 * ['target_id' => string, 'admin_id' => string, 'remaining_seconds' => int].
 *
 * @see BR-33, BR-34, BR-35 · PRD §9.18 · CONSTITUTION.md Articles 5, 11, 13, 23
 */
final class ImpersonationBar extends UiComponent
{
    public mixed $context;

    public int $remaining;

    public string $targetName;

    public string $endsAt;

    public ?string $stopUrl;

    public bool $isActive;

    public function __construct(mixed $impersonation = null)
    {
        $this->context = $impersonation ?? self::shared('impersonation');
        $this->remaining = max(0, (int) data_get($this->context, 'remaining_seconds', 0));

        // During a preview Auth::user() IS the previewed account, so the name
        // needs no extra query beyond the profile this page already wants.
        $target = $this->context !== null ? auth()->user()?->loadMissing('profile') : null;

        $this->targetName = trim((string) (
            data_get($target, 'profile.short_name_ar')
            ?: data_get($target, 'profile.full_name_ar')
            ?: data_get($target, 'email', '')
        ));

        // An absolute instant for the countdown, produced by the one clock the
        // platform is allowed to read (art. 11).
        $this->endsAt = Clock::now()->addSeconds($this->remaining)->toIso8601ZuluString();

        $this->stopUrl = Route::has('admin.impersonation.stop') ? route('admin.impersonation.stop') : null;
        $this->isActive = $this->context !== null && $this->stopUrl !== null;
    }

    public function render(): View
    {
        return view('components.layout.impersonation-bar');
    }
}
