<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Resource;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The training kit. Files are never exposed by direct URL: a controller checks
 * this policy first, then hands out a signed URL that lives for 15 minutes
 * (PRD §12.5). The download counter is trainer-facing only, and a preview
 * session must never move it (BR-34).
 *
 * @see BR-22, BR-23, BR-34 · PRD §9.12, §12.5 · CONSTITUTION Art. 22
 */
final class ResourcePolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->roles->isActive($user);
    }

    public function view(User $user, Resource $resource): bool
    {
        return $this->reaches($user, (string) $resource->cohort_id);
    }

    public function download(User $user, Resource $resource): bool
    {
        return $this->view($user, $resource);
    }

    public function viewDownloadCount(User $user, Resource $resource): bool
    {
        return $this->staffOf($user, (string) $resource->cohort_id);
    }

    public function create(User $user, Resource $resource): bool
    {
        return $this->staffOf($user, (string) $resource->cohort_id) && $this->writesAllowed();
    }

    public function update(User $user, Resource $resource): bool
    {
        return $this->staffOf($user, (string) $resource->cohort_id) && $this->writesAllowed();
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $this->staffOf($user, (string) $resource->cohort_id) && $this->writesAllowed();
    }

    public function forceDelete(User $user, Resource $resource): bool
    {
        return false;
    }
}
