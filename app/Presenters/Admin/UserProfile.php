<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
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
 * @see BR-28, BR-29, BR-32, BR-33, BR-35 · PRD §4.4, §4.5, §9.18
 */
final class UserProfile extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, AuditLog>  $auditEntries
     */
    public static function from(
        User $subject,
        User $viewer,
        Collection $enrollments,
        Collection $auditEntries,
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
            // Only a trainer or an administrator can be given a cohort from
            // the cohorts screen. A participant is seated when the account is
            // created, and pointing their empty state at that screen sent the
            // administrator to look for a control that is not there (D-69).
            'attachesFromCohorts' => in_array($role?->value, ['trainer', 'admin'], true),
            'isSuspended' => $isSuspended,
            'toggleStatusValue' => $isSuspended ? UserStatus::Active->value : UserStatus::Suspended->value,

            'canBeAdministered' => $gate->allows('update', $subject) && ! $viewer->is($subject),
            'canChangeRole' => $gate->allows('changeRole', $subject),
            'canChangeStatus' => $gate->allows('suspend', $subject),
            'canBePreviewed' => $gate->allows('preview', $subject),
            'canEnroll' => $gate->allows('enroll', $subject),

            'enrollments' => $enrollments->map(
                static fn (Enrollment $row): UserEnrollmentRow => UserEnrollmentRow::from($row),
            )->values(),
            'auditEntries' => $auditEntries->map(
                static fn (AuditLog $row): AuditEntry => AuditEntry::from($row),
            )->values(),
        ]);
    }
}
