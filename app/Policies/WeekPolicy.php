<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Week;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Weeks belong to a cohort and inherit its scope exactly.
 *
 * @see BR-22, BR-23 · PRD §4.2, §7.3 · CONSTITUTION Art. 22
 */
final class WeekPolicy
{
    use InteractsWithScope;

    public function view(User $user, Week $week): bool
    {
        return $this->reaches($user, (string) $week->cohort_id);
    }

    public function create(User $user, Week $week): bool
    {
        return $this->staffOf($user, (string) $week->cohort_id) && $this->writesAllowed();
    }

    public function update(User $user, Week $week): bool
    {
        return $this->staffOf($user, (string) $week->cohort_id) && $this->writesAllowed();
    }

    public function delete(User $user, Week $week): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }
}
