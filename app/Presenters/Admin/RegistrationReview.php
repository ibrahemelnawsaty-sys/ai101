<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Cohort;
use App\Models\Enrollment;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * One registration request opened for a decision.
 *
 * `hasFreeSeat` is read from the cohort at render time and is the reason the
 * approve button may be unavailable — but it is NOT the protection: approval
 * locks the cohort row and re-counts the seats inside a transaction, so two
 * administrators approving at the same instant cannot oversell the last place
 * (art. 5).
 *
 * @see PRD §9.2.3, §9.18 · BR-27, BR-31
 */
final class RegistrationReview extends ViewModel
{
    use PresentsFormValues;

    public static function from(Enrollment $enrollment): self
    {
        $user = self::related($enrollment, 'user');
        $profile = self::related($user, 'profile');
        $cohort = self::related($enrollment, 'cohort');

        $capacity = (int) (self::attr($cohort, 'capacity') ?? 0);
        $taken = (int) (self::attr($cohort, 'seats_taken') ?? 0);

        return new self([
            'id' => (string) $enrollment->getKey(),
            'name' => (string) (
                $profile?->getAttribute('full_name_ar')
                ?? self::attr($user, 'email')
                ?? '—'
            ),
            'fullNameAr' => self::text($profile, 'full_name_ar'),
            'fullNameEn' => self::text($profile, 'full_name_en'),
            'email' => self::text($user, 'email'),
            'phone' => self::text($profile, 'phone'),
            'cohortName' => self::text($cohort, 'name'),
            'capacity' => $capacity,
            'seatsTaken' => $taken,
            'hasFreeSeat' => $cohort instanceof Cohort ? $cohort->seatsRemaining() > 0 : false,
            'requestedAt' => $enrollment->getAttribute('created_at'),
        ]);
    }
}
