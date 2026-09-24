<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\User;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Services\Permissions\RoleResolver;
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
            // What an empty enrolment list MEANS depends on the role, and the
            // system administrator reading it cannot open the cohorts screen,
            // so the text names who acts rather than linking there (D-69,
            // D-117). It used to tell a system administrator's page that the
            // supervisor would seat them in a cohort — a role that reaches none.
            'enrollmentsEmptyBody' => (string) __(match ($role) {
                UserRole::Trainer, UserRole::Coordinator => 'admin.users.enrollments_empty_body',
                UserRole::Admin => 'admin.users.enrollments_empty_supervisor_body',
                UserRole::SystemAdmin => 'admin.users.enrollments_empty_system_admin_body',
                default => 'admin.users.enrollments_empty_participant_body',
            }),
            'isSuspended' => $isSuspended,
            'toggleStatusValue' => $isSuspended ? UserStatus::Active->value : UserStatus::Suspended->value,

            // BR-32 is a reason the screen SHOWS: the last active supervisor
            // or system administrator keeps their role and their status, and
            // the note above the controls says why they are disabled (D-117 —
            // they were disabled in silence once the reader and the account
            // stopped sharing a role).
            'canBeAdministered' => $gate->allows('update', $subject)
                && ! $viewer->is($subject)
                && ! app(RoleResolver::class)->isLastActiveHolder($subject),
            'canChangeRole' => $gate->allows('changeRole', $subject),
            // Activating is `restore`, suspending is `suspend`: the button is
            // one toggle, so it asks the ability of the way it will go.
            'canChangeStatus' => $gate->allows($isSuspended ? 'restore' : 'suspend', $subject),
            'canBePreviewed' => $gate->allows('preview', $subject),
            'previewBlockedReason' => UserRow::previewBlockedReason($viewer, $subject),

            'enrollments' => $enrollments->map(
                static fn (Enrollment $row): UserEnrollmentRow => UserEnrollmentRow::from($row),
            )->values(),
        ]);
    }
}
