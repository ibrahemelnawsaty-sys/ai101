<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DigitalCard;
use App\Models\User;
use App\Policies\Concerns\InteractsWithScope;

/**
 * The digital card. Its holder views and downloads it; an admin may revoke it.
 * The public /verify/{token} page is unauthenticated by design and shows only
 * the fields BR-25 permits, so it does not consult this policy.
 *
 * @see BR-22, BR-25 · PRD §9.6 · CONSTITUTION Art. 22
 */
final class DigitalCardPolicy
{
    use InteractsWithScope;

    public function view(User $user, DigitalCard $card): bool
    {
        return $this->owns($user, (string) $card->user_id) || $this->admin($user);
    }

    public function download(User $user, DigitalCard $card): bool
    {
        return $card->revoked_at === null && $this->view($user, $card);
    }

    public function revoke(User $user, DigitalCard $card): bool
    {
        return $this->admin($user) && $this->writesAllowed();
    }

    public function delete(User $user, DigitalCard $card): bool
    {
        return false;
    }
}
