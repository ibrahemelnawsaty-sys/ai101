<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * One account's full profile, with the account-preview entry point.
 *
 * Every `can…` flag is asked of UserPolicy — the same policy the target route
 * re-checks on every request. The flags exist to EXPLAIN an unavailable action,
 * never to be the protection: BR-32 (one active administrator always remains)
 * and "no action on your own account" are enforced by the policy and again in
 * the controller (art. 5, BR-28).
 *
 * `toggleStatusValue` is the value the suspend/activate form posts, decided
 * here rather than in the template, so the screen cannot invent a third state.
 *
 * Since D-117 this is the system administrator's page: the account's own audit
 * trail (IP addresses) and the cohort seating control left it.
 *
 * @see BR-28, BR-29, BR-32, BR-33, BR-35 · PRD §4.4, §4.5, §9.18 · D-117
 */
final class UserProfile extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    public static function from(
        User $subject,
        User $viewer,
        Collection $enrollments,
    ): self {
        $profile = self::related($subject, 'profile');

        $role = $subject->getAttribute('role');
        $role = $role instanceof UserRole ? $role : null;

        $status = $subject->getAttribute('status');
        $status = $status instanceof UserStatus ? $status : null;

        $isSuspended = $status === UserStatus::Suspended;
        $gate = Gate::forUser($viewer);

        return new self([
            'id' => (string) $subject->getKey(),
            'name' => (string) ($profile?->getAttribute('full_name_ar') ?? $subject->getAttribute('email')),
            'fullNameAr' => self::text($profile, 'full_name_ar'),
            'fullNameEn' => self::text($profile, 'full_name_en'),
            'email' => (string) $subject->getAttribute('email'),
            'phone' => self::text($profile, 'phone'),

            'role' => $role->value ?? '',
            'roleLabel' => $role?->label() ?? '—',
            'roleVariant' => self::roleVariantOf($role),
            'statusLabel' => $status?->label() ?? '—',
            'statusVariant' => self::userStatusVariantOf($status),
            'statusIcon' => self::userStatusIconOf($status),

            'lastLoginAt' => $subject->getAttribute('last_login_at'),
            'registeredAt' => $subject->getAttribute('created_at'),
            'awaitingVerification' => $subject->getAttribute('email_verified_at') === null,

            'isSelf' => $viewer->is($subject),
            // Staff are given a cohort from the cohorts screen by e-mail; a
            // participant created without one is seated there too since
            // D-117. Both are the supervisor's to do, so the empty state names
            // who does it rather than linking to a screen the system
            // administrator reading it cannot open (D-69, D-117).
            'attachesFromCohorts' => in_array($role?->value, ['trainer', 'coordinator', 'admin'], true),
            'isSuspended' => $isSuspended,
            'toggleStatusValue' => $isSuspended ? UserStatus::Active->value : UserStatus::Suspended->value,

            'canBeAdministered' => $gate->allows('update', $subject) && ! $viewer->is($subject),
            'canChangeRole' => $gate->allows('changeRole', $subject),
            'canChangeStatus' => $gate->allows('suspend', $subject),
            'canBePreviewed' => $gate->allows('preview', $subject),

            'enrollments' => $enrollments->map(
                static fn (Enrollment $row): UserEnrollmentRow => UserEnrollmentRow::from($row),
            )->values(),
        ]);
    }
}
