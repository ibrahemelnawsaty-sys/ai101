<?php

declare(strict_types=1);

namespace App\Presenters\Support;

use App\Models\Cohort;
use App\Models\User;
use App\Services\Certificates\CertificateEligibility;

/**
 * Why someone falls short of a certificate, worded for the STAFF who read it.
 *
 * CertificateEligibility answers in the participant's own voice — «your attendance is
 * 33%» — because the participant's certificate page prints it. The administrator's
 * list of who falls short and the trainer's at-risk list print the same reasons next to
 * a NAME, where «your» is nobody's: whose attendance? So the staff screens ask the
 * service for the same reasons untranslated (`reasonKeys()`, which is what decides
 * them) and word them from a parallel set of strings. Nothing is decided here: the
 * conditions, their order and every number are the service's.
 *
 * @see BR-26 · PRD §9.17 · CONSTITUTION art. 6, art. 15 · D-147
 */
final class StaffReasons
{
    /**
     * @return array<string, string> condition => reason, in the service's order
     */
    public static function of(CertificateEligibility $eligibility, User $user, Cohort $cohort): array
    {
        $reasons = [];

        foreach ($eligibility->reasonKeys($user, $cohort) as $condition => $reason) {
            $key = str_replace('certificates.reasons.', 'certificates.reasons_staff.', $reason['key']);

            $reasons[$condition] = (string) __($key, $reason['replacements']);
        }

        return $reasons;
    }
}
