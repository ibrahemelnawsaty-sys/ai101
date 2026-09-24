<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The general supervisor's console: the information home and the platform
 * settings. Neither screen reads or writes one model of its own, so neither
 * had a policy of its own either — the home borrowed `viewAny` on accounts and
 * the settings borrowed `update` on the landing page. D-117 gave accounts and
 * the landing page to the system administrator, and a borrowed permission
 * would have carried both screens away with them.
 *
 * Registered as two named abilities in AuthServiceProvider:
 *   `console.view`     — the home of /admin (PRD §9.18's six figures and queues)
 *   `console.settings` — the general settings, the notification defaults and
 *                        the e-mail templates; refused during a preview, as the
 *                        screen was before (BR-33)
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
        return $this->admin($user) && $this->writesAllowed();
    }
}
