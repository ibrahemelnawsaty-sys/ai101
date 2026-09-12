<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Concerns\PresentsVariants;
use App\Support\ViewModel;

/**
 * One registration request, as the console queue and the requests table show it.
 *
 * The same row appears on the dashboard queue and on the registrations screen,
 * so it is shaped once. `isPending` is what decides whether the row offers a
 * decision or merely reports one — and it is a reading of the enrolment status
 * the server holds, never of anything the browser sent (art. 5).
 *
 * @see PRD §9.2.3, §9.18 · BR-27, BR-28, BR-31
 */
final class RegistrationRow extends ViewModel
{
    use PresentsFormValues;
    use PresentsVariants;

    public static function from(Enrollment $enrollment): self
    {
        $user = self::related($enrollment, 'user');
        $profile = self::related($user, 'profile');
        $cohort = self::related($enrollment, 'cohort');

        $status = $enrollment->getAttribute('status');
        $status = $status instanceof EnrollmentStatus ? $status : null;

        return new self([
            'id' => (string) $enrollment->getKey(),
            'name' => $profile?->getAttribute('full_name_ar') ?? ($user?->getAttribute('email') ?? '—'),
            'email' => $user?->getAttribute('email') ?? '—',
            'cohortName' => $cohort?->getAttribute('name') ?? '—',
            'requestedAt' => $enrollment->getAttribute('created_at'),
            'isPending' => $enrollment->awaitsDecision(),
            'stateLabel' => $status?->label() ?? '—',
            'stateVariant' => self::enrollmentVariantOf($status),
            'stateIcon' => self::enrollmentIconOf($status),
            // Who settled it. The trail holds the actor and the instant; this
            // column only says that a decision exists, in the status's own words.
            'decidedByLabel' => $status === EnrollmentStatus::Pending ? '—' : ($status?->label() ?? '—'),
        ]);
    }
}
