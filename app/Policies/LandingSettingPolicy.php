<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LandingSetting;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Landing-page content: seats, countdown, copy, FAQ and the registration
 * switch. All of it is editable content (BR-31), and all of it belongs to the
 * system administrator since D-117.
 *
 * The platform settings screen used to borrow `update` here as its own
 * permission, although it never writes a landing_settings row. It has one of
 * its own now (ConsolePolicy), so moving the landing page to the system
 * administrator did not move the platform settings with it.
 *
 * @see BR-31 · PRD §9.18 · CONSTITUTION Art. 22 · D-117
 */
final class LandingSettingPolicy
{
    use InteractsWithScope;

    public function view(User $user, LandingSetting $setting): bool
    {
        return $this->systemAdmin($user);
    }

    public function update(User $user, LandingSetting $setting): bool
    {
        return $this->systemAdmin($user) && $this->writesAllowed();
    }
}
