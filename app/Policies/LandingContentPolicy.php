<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The landing page's copy: every text a visitor reads, in both languages.
 *
 * The system administrator's alone since D-117 — the owner took the landing
 * page away from the general supervisor on purpose. Reading the editor and
 * previewing a draft need the role; publishing and resetting need it AND are
 * refused for the whole duration of an account preview, like every other write
 * (BR-33).
 *
 * @see BR-31, BR-33 · PRD §9.18 · CONSTITUTION Art. 22 · D-114, D-117
 */
final class LandingContentPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->systemAdmin($user);
    }

    public function update(User $user): bool
    {
        return $this->systemAdmin($user) && $this->writesAllowed();
    }
}
