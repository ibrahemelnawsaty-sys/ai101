<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;
use Illuminate\Support\Facades\Gate;

/**
 * One account on the admin accounts table.
 *
 * `canBePreviewed` is asked of UserPolicy::preview, which is the same check the
 * preview route runs on every request (BR-35). The flag exists so the row can
 * explain WHY the action is unavailable instead of showing a dead button —
 * hiding a control is never the protection (art. 5).
 *
 * @see BR-28, BR-32, BR-35 · PRD §4.5, §9.18 · CONSTITUTION art. 5
 */
final class UserRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(User $subject, User $viewer): self
    {
        $profile = self::related($subject, 'profile');

        $role = $subject->getAttribute('role');
        $role = $role instanceof UserRole ? $role : null;

        $status = $subject->getAttribute('status');
        $status = $status instanceof UserStatus ? $status : null;

        return new self([
            'id' => (string) $subject->getKey(),
            'name' => (string) ($profile?->getAttribute('full_name_ar') ?? $subject->getAttribute('email')),
            'email' => (string) $subject->getAttribute('email'),
            'roleLabel' => $role?->label() ?? '—',
            'roleVariant' => self::roleVariantOf($role),
            'statusLabel' => $status?->label() ?? '—',
            'statusVariant' => self::userStatusVariantOf($status),
            'statusIcon' => self::userStatusIconOf($status),
            'lastLoginAt' => $subject->getAttribute('last_login_at'),
            'awaitingVerification' => $subject->getAttribute('email_verified_at') === null,
            'canBePreviewed' => Gate::forUser($viewer)->allows('preview', $subject),
        ]);
    }
}
