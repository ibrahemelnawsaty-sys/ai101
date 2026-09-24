<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The two administration screens that read or write no model of their own:
 * the information home and the platform settings. Neither had a policy of its
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
}
