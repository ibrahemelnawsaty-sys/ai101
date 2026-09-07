<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * Certificates. Issuing, re-issuing and revoking are admin-only (PRD §4.2).
 * A participant downloads their own certificate and no other; the public
 * verification page sits deliberately outside this policy because it is
 * unauthenticated and exposes only the four permitted fields (BR-25).
 *
 * @see BR-22, BR-25, BR-26 · PRD §9.17 · CONSTITUTION Art. 22
 */
final class CertificatePolicy
{
    use InteractsWithScope;

    public function viewAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function view(User $user, Certificate $certificate): bool
    {
        return $this->owns($user, (string) $certificate->user_id)
            || $this->admin($user)
            || $this->trainerOf($user, (string) $certificate->cohort_id);
    }

    /** Only the holder of a certificate that is not revoked may download it. */
    public function download(User $user, Certificate $certificate): bool
    {
        if ($certificate->revoked_at !== null) {
            return $this->admin($user);
        }

        return $this->owns($user, (string) $certificate->user_id) || $this->admin($user);
    }

    public function issue(User $user): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    /** Manual override for someone who did not meet the two conditions (PRD §9.17). */
    public function issueWithOverride(User $user): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function revoke(User $user, Certificate $certificate): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function reissue(User $user, Certificate $certificate): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function delete(User $user, Certificate $certificate): bool
    {
        return false;
    }

    public function forceDelete(User $user, Certificate $certificate): bool
    {
        return false;
    }
}
