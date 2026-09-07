<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Program;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Programmes are an admin-only object: only an admin creates, edits or archives
 * one. Everyone signed in may read the programme they are attached to.
 *
 * @see BR-31 · PRD §4.2, §9.18 · CONSTITUTION Art. 22
 */
final class ProgramPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Program $program): bool
    {
        return $this->roles->isActive($user);
    }

    public function create(User $user): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function update(User $user, Program $program): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function archive(User $user, Program $program): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function delete(User $user, Program $program): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function forceDelete(User $user, Program $program): bool
    {
        return false;
    }
}
