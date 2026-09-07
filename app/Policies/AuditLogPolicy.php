<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The audit trail is readable by admins and writable by nobody. Every mutating
 * ability here returns false unconditionally: BR-27 makes the log append-only,
 * and the database user is granted neither UPDATE nor DELETE on the table.
 *
 * @see BR-27 · PRD §9.18, §12.2 · CONSTITUTION Art. 8
 */
final class AuditLogPolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $this->admin($user);
    }

    public function export(User $user): bool
    {
        return $this->admin($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function forceDelete(User $user, AuditLog $log): bool
    {
        return false;
    }
}
