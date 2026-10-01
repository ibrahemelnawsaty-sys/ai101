<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Services\Certificates\CertificateEligibility;
use App\Services\Grading\ScoreCalculator;
use App\Services\Time\Clock;

/**
 * The average attendance and the average mark of cohorts — the numbers on the reports and on the
 * administrator's home.
 *
 * They are worked out when the page is drawn, from the services that own the two figures: the
 * attendance rate from CertificateEligibility, the mark out of 100 from ScoreCalculator. The
 * `enrollments.attendance_rate` and `enrollments.final_score` columns they used to read are not
 * consulted at all (D-150, option A): nothing keeps them current, and a second place for a figure
 * is the drift art. 6 forbids. So a cohort's average is the average of what its certificates
 * screen shows, person by person.
 *
 * WHO IS AVERAGED — stated here so it is not assumed: the cohort's participants who are enrolled,
 * active or completed (the two states CertificateEligibility::isEnrolled accepts). Trainers and
 * coordinators are not participants; someone who withdrew, or has not been accepted yet, is not
 * enrolled.
 *
 * WHEN THERE IS NOTHING TO AVERAGE — a cohort with no participant, or whose first session has not
 * ended (attendance), or in which nobody has a mark yet (score) — the average is null, drawn «—».
 * A session that ended with nobody present is a measurement: 0%. Nothing marked is not 0 out of
 * 100: it is nothing measured.
 *
 * The queries are per COHORT, never per person: CertificateEligibility::attendanceRates() and
 * ScoreCalculator::finalScores() answer for a whole list at once (art. 19).
 *
 * @see BR-11, BR-22, BR-26 · PRD §9.18 · CONSTITUTION art. 6, art. 19 · D-150
 */
final class CohortAverages
{
    public function __construct(
        private readonly CertificateEligibility $eligibility,
        private readonly ScoreCalculator $scores,
    ) {}

    /**
     * @param  iterable<Cohort>  $cohorts
     * @return array{
     *     cohorts: array<string, array{attendance: float|null, score: float|null}>,
     *     overall: array{attendance: float|null, score: float|null}
     * }
     *                        `overall` pools the PEOPLE of every cohort measured on that figure — a cohort
     *                        of sixty weighs sixty, not one.
     */
    public function of(iterable $cohorts): array
    {
        $list = [];

        foreach ($cohorts as $cohort) {
            $list[(string) $cohort->getKey()] = $cohort;
        }

        $people = $this->participantsByCohort(array_keys($list));
        $now = Clock::now();

        $perCohort = [];
        $pooled = ['attendance' => ['sum' => 0.0, 'people' => 0], 'score' => ['sum' => 0.0, 'people' => 0]];

        foreach ($list as $cohortId => $cohort) {
            $ids = $people[$cohortId] ?? [];
            $perCohort[$cohortId] = ['attendance' => null, 'score' => null];

            if ($ids === []) {
                continue;
            }

            if ($this->eligibility->sessionProgress($cohort, $now)['ended'] > 0) {
                $rates = $this->eligibility->attendanceRates($ids, $cohort, $now);

                $perCohort[$cohortId]['attendance'] = array_sum($rates) / count($rates);
                $pooled['attendance']['sum'] += array_sum($rates);
                $pooled['attendance']['people'] += count($rates);
            }

            $marks = $this->scores->finalScores($ids, $cohort);

            // Nobody has a mark yet: nothing has been measured, which is not the same as zero.
            if (array_filter($marks, static fn (float $mark): bool => $mark > 0.0) !== []) {
                $perCohort[$cohortId]['score'] = array_sum($marks) / count($marks);
                $pooled['score']['sum'] += array_sum($marks);
                $pooled['score']['people'] += count($marks);
            }
        }

        return [
            'cohorts' => $perCohort,
            'overall' => [
                'attendance' => $pooled['attendance']['people'] > 0 ? $pooled['attendance']['sum'] / $pooled['attendance']['people'] : null,
                'score' => $pooled['score']['people'] > 0 ? $pooled['score']['sum'] / $pooled['score']['people'] : null,
            ],
        ];
    }

    /**
     * @param  list<string>  $cohortIds
     * @return array<string, list<string>> cohort id => its enrolled participants' ids
     */
    private function participantsByCohort(array $cohortIds): array
    {
        if ($cohortIds === []) {
            return [];
        }

        $rows = Enrollment::query()
            ->whereIn('cohort_id', $cohortIds)
            ->where('role_in_cohort', EnrollmentRole::Participant->value)
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
            ->get(['cohort_id', 'user_id']);

        $byCohort = [];

        foreach ($rows as $row) {
            $byCohort[(string) $row->getAttribute('cohort_id')][] = (string) $row->getAttribute('user_id');
        }

        return $byCohort;
    }
}
