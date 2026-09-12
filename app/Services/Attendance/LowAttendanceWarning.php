<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\CohortStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Events\AttendanceLow;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Notifications\InAppNotifier;
use Carbon\CarbonImmutable;

/**
 * "Your attendance has dropped below the required rate" — the PRD §9.16.1 row
 * "attendance rate dropped: the trainee, upon going below the threshold,
 * platform and e-mail".
 *
 * WHAT DECIDES IT, AND WHERE THAT COMES FROM
 *   · the rate is CertificateEligibility's — the figure the trainee's own
 *     attendance screen shows and the certificate is judged on. The reconciler
 *     stores a rate of its own over a different set of sessions; a letter
 *     quoting that one could name a number no screen shows;
 *   · the threshold is the cohort's minimum for the certificate (§9.17);
 *   · "upon dropping below" is a CROSSING: the notice is written once when the
 *     rate goes under, and re-armed when it climbs back to the threshold, so a
 *     second drop is a second notice. A column on the enrolment remembers it;
 *   · nothing is measured before the first session ends — the rate of zero
 *     sessions is not an absence, and warning a whole cohort on day one would
 *     be the platform lying;
 *   · only while the cohort is running.
 *
 * The letter's words depend on whether a session remains: "attending the
 * coming sessions raises it" is false after the last one (D-77).
 *
 * THE CLAIM IS THE DE-DUPLICATION. Each notice is taken by a conditional
 * UPDATE on the enrolment; two overlapping reconciler runs cannot both win.
 *
 * @see PRD §9.16.1, §9.17 · BR-26 · D-26, D-77
 */
final class LowAttendanceWarning
{
    public const TYPE = 'attendance_low';

    public function __construct(
        private readonly CertificateEligibility $eligibility,
        private readonly InAppNotifier $notifier,
    ) {}

    /**
     * @return int how many trainees were warned
     */
    public function check(Cohort $cohort, CarbonImmutable $now): int
    {
        if ($cohort->getAttribute('status') !== CohortStatus::Running) {
            return 0;
        }

        $progress = $this->eligibility->sessionProgress($cohort, $now);

        if ($progress['ended'] === 0) {
            return 0;
        }

        $ids = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        $rates = $this->eligibility->attendanceRates($ids, $cohort, $now);
        $required = $this->eligibility->minAttendanceRate($cohort);
        $stamp = $now->format('Y-m-d H:i:s');

        $recovered = array_keys(array_filter($rates, static fn (float $rate): bool => $rate >= $required));
        $below = array_keys(array_filter($rates, static fn (float $rate): bool => $rate < $required));

        // Re-arm whoever climbed back: their next drop is a new crossing.
        if ($recovered !== []) {
            Enrollment::query()
                ->where('cohort_id', $cohort->getKey())
                ->whereIn('user_id', $recovered)
                ->whereNotNull('attendance_low_notified_at')
                ->update(['attendance_low_notified_at' => null, 'updated_at' => $stamp]);
        }

        $claimed = [];

        foreach ($below as $userId) {
            $won = Enrollment::query()
                ->where('cohort_id', $cohort->getKey())
                ->where('user_id', $userId)
                ->where('role_in_cohort', EnrollmentRole::Participant->value)
                ->where('status', EnrollmentStatus::Active->value)
                ->whereNull('attendance_low_notified_at')
                ->update(['attendance_low_notified_at' => $stamp, 'updated_at' => $stamp]);

            if ($won === 1) {
                $claimed[] = (string) $userId;
            }
        }

        if ($claimed === []) {
            return 0;
        }

        $sessionsRemain = $progress['remaining'] > 0;
        $minimum = Present::decimal($required);

        foreach (User::query()->whereKey($claimed)->get() as $user) {
            $current = Present::decimal($rates[(string) $user->getKey()] ?? 0.0);
            $values = ['current' => $current, 'required' => $minimum];

            $this->notifier->notify(
                [(string) $user->getKey()],
                self::TYPE,
                (string) __('notifications.types.attendance_low.title', $values),
                (string) __($sessionsRemain
                    ? 'notifications.types.attendance_low.body'
                    : 'notifications.types.attendance_low.body_final', $values),
                route('attendance.index'),
                $now,
            );

            AttendanceLow::dispatch($user, $current, $minimum, $sessionsRemain);
        }

        return count($claimed);
    }
}
