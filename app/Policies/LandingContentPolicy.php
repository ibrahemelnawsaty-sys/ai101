<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The landing page's copy: every text a visitor reads, in both languages.
 *
 * Reading the editor and previewing a draft are admin-only; publishing and
 * resetting are admin-only AND refused for the whole duration of an account
 * preview, like every other write (BR-33).
 *
 * @see BR-31, BR-33 · PRD §9.18 · CONSTITUTION Art. 22 · D-114
 */
final class LandingContentPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function update(User $user): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }
}
