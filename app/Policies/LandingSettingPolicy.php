<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LandingSetting;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Landing-page content: seats, countdown, copy, FAQ and the registration
 * switch. All of it is editable content and all of it is admin-only (BR-31).
 *
 * @see BR-31 · PRD §9.18 · CONSTITUTION Art. 22
 */
final class LandingSettingPolicy
{
    use InteractsWithScope;

    public function view(User $user, LandingSetting $setting): bool
    {
        return $this->admin($user);
    }

    public function update(User $user, LandingSetting $setting): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }
}
