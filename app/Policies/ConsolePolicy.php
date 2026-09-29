<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The administration screens that read or write no model of their own:
 * the information home, the platform settings and (D-133) the roles page. Neither had a policy of its
 * own — the home borrowed `viewAny` on accounts and the settings borrowed
 * `update` on the landing page — so D-117, which moved accounts and the landing
 * page, gave each an ability that names its own owner.
 *
 * Registered as two named abilities in AuthServiceProvider:
 *   `console.view`     — the general supervisor's home of /admin (PRD §9.18's
 *                        six figures and queues)
 *   `console.settings` — the system administrator's platform settings: the
 *                        general settings, the notification defaults and the
 *                        e-mail templates. The owner kept them with the role
 *                        that runs the platform, not the programme. Refused
 *                        during a preview, as the screen was before (BR-33).
 *   `console.roles`    — the page that explains the five roles (D-133): the
 *                        system administrator's alone; it changes nothing.
 *
 * @see BR-33 · PRD §9.18 · CONSTITUTION Art. 22 · D-117
 */
final class ConsolePolicy
{
    use InteractsWithScope;

    public function view(User $user): bool
    {
        return $this->admin($user);
    }

    public function settings(User $user): bool
    {
        return $this->systemAdmin($user) && $this->writesAllowed();
    }

    /**
     * The page that explains the five roles (D-133): the system administrator's,
     * who changes them. It writes nothing, so it does not ask writesAllowed().
     * During an account preview the acting account is the PREVIEWED one, never a
     * system administrator (BR-35), so the role middleware refuses it before this
     * is asked.
     */
    public function roles(User $user): bool
    {
        return $this->systemAdmin($user);
    }
}
