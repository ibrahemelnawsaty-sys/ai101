<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Session;
use App\Models\User;
use App\Services\Attendance\AttendanceWindow;
use App\Services\Grading\ScoreCalculator;
use App\Services\Time\Clock;
use App\Support\AttendanceCounting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BR-26 — a certificate is issued only when BOTH conditions hold:
 *
 *   attendance rate >= cohort.min_attendance_rate   (default 75)
 *   final score     >= cohort.pass_score            (default 60 of 100)
 *
 * Neither compensates the other. A perfect score does not excuse absence and
 * full attendance does not excuse a failing mark.
 *
 * @see BR-26 · PRD §9.17 · CONTRACT §8
 */
final class CertificateEligibility
{
    private const DEFAULT_MIN_ATTENDANCE_RATE = 75.0;

    private const PRECISION = 2;

    public function __construct(
        private readonly ScoreCalculator $scores,
        private readonly AttendanceWindow $window,
    ) {}

    /**
     * Percentage of the cohort's finished, non-cancelled sessions the
     * participant actually attended.
     *
     * With no finished session yet the rate is zero, not one hundred: refusing
     * is the safe answer when there is nothing to measure (art. 7).
     */
    public function attendanceRate(User $user, Cohort $cohort, ?CarbonImmutable $at = null): float
    {
        $now = $at ?? Clock::now();
        $sessionIds = $this->countableSessionIds($cohort, $now);
        $total = count($sessionIds);

        if ($total === 0) {
            return 0.0;
        }

        $attended = DB::table('attendances')
            ->whereIn('session_id', $sessionIds)
            ->where('user_id', $user->getKey())
            ->whereIn('status', AttendanceCounting::countedAsAttendedValues())
            ->count();

        return round(((float) $attended / (float) $total) * 100, self::PRECISION);
    }

    /**
     * Condition one of BR-26.
     */
    public function meetsAttendance(User $user, Cohort $cohort): bool
    {
        return $this->attendanceRate($user, $cohort) >= $this->minAttendanceRate($cohort);
    }

    /**
     * Condition two of BR-26.
     */
    public function meetsScore(User $user, Cohort $cohort): bool
    {
        return $this->scores->finalScore($user, $cohort) >= $this->scores->passScore($cohort);
    }

    /**
     * BR-26 — both conditions, with no compensation between them.
     */
    public function isEligible(User $user, Cohort $cohort): bool
    {
        if (! $this->isEnrolled($user, $cohort)) {
            return false;
        }

        return $this->meetsAttendance($user, $cohort) && $this->meetsScore($user, $cohort);
    }

    /**
     * Why the participant is not eligible, keyed by the condition that failed:
     * `enrollment`, `attendance`, `score`. An empty array means eligible.
     *
     * The values are the resolved Arabic sentences, because that is what the
     * admin bulk-issue list and the participant screen both display, and
     * PROJECT-CONTRACT §8 words this method as returning the reasons in
     * Arabic. No Arabic literal appears in this file: every sentence comes from
     * lang/ar/certificates.php through __() (art. 15). Callers that need the
     * raw keys instead - a queued job building an e-mail in another locale, for
     * instance - use reasonKeys().
     *
     * Both conditions are reported when both fail: a single reason would let a
     * participant fix one and be surprised by the other (BR-26).
     *
     * @return array<string, string>
     */
    public function reasons(User $user, Cohort $cohort): array
    {
        $reasons = [];

        foreach ($this->reasonKeys($user, $cohort) as $condition => $reason) {
            $reasons[$condition] = (string) __($reason['key'], $reason['replacements']);
        }

        return $reasons;
    }

    /**
     * The same answer as reasons(), untranslated.
     *
     * @return array<string, array{key: string, replacements: array<string, string>}>
     */
    public function reasonKeys(User $user, Cohort $cohort): array
    {
        if (! $this->isEnrolled($user, $cohort)) {
            return [
                'enrollment' => [
                    'key' => 'certificates.reasons.not_enrolled',
                    'replacements' => [],
                ],
            ];
        }

        $reasons = [];

        $rate = $this->attendanceRate($user, $cohort);
        $requiredRate = $this->minAttendanceRate($cohort);

        if ($rate < $requiredRate) {
            $reasons['attendance'] = [
                'key' => 'certificates.reasons.attendance_below_minimum',
                'replacements' => [
                    'current' => $this->number($rate),
                    'required' => $this->number($requiredRate),
                ],
            ];
        }

        $score = $this->scores->finalScore($user, $cohort);
        $requiredScore = $this->scores->passScore($cohort);

        if ($score < $requiredScore) {
            $reasons['score'] = [
                'key' => 'certificates.reasons.score_below_pass',
                'replacements' => [
                    'current' => $this->number($score),
                    'max' => $this->number((float) ScoreCalculator::GRAND_TOTAL),
                    'required' => $this->number($requiredScore),
                ],
            ];
        }

        return $reasons;
    }

    /**
     * attendanceRate() for a whole roster, in two queries instead of two per
     * person.
     *
     * The trainer's attendance matrix and its at-risk list both need the rate
     * of everybody in a cohort at once. Asking attendanceRate() in a loop is
     * the classic N+1 that art. 19 forbids outright, and computing the rate in
     * the controller instead would put BR-26's counting rule in a second place
     * (art. 6). So the rule stays here and only the loop moves.
     *
     * The answer is identical to attendanceRate() by construction: same
     * countable sessions, same counted statuses, same rounding.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, float> user id => rate, every id present
     */
    public function attendanceRates(array $userIds, Cohort $cohort, ?CarbonImmutable $at = null): array
    {
        $rates = [];

        foreach ($userIds as $userId) {
            $rates[(string) $userId] = 0.0;
        }

        if ($userIds === []) {
            return $rates;
        }

        $sessionIds = $this->countableSessionIds($cohort, $at ?? Clock::now());
        $total = count($sessionIds);

        if ($total === 0) {
            return $rates;
        }

        $rows = DB::table('attendances')
            ->whereIn('session_id', $sessionIds)
            ->whereIn('user_id', $userIds)
            ->whereIn('status', AttendanceCounting::countedAsAttendedValues())
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->groupBy('user_id')
            ->get();

        foreach ($rows as $row) {
            $userId = (string) $row->user_id;

            if (array_key_exists($userId, $rates)) {
                $rates[$userId] = round(((float) $row->aggregate / (float) $total) * 100, self::PRECISION);
            }
        }

        return $rates;
    }

    /**
     * The two numbers behind attendanceRate(): how many finished, non-cancelled
     * sessions there were, and how many of them the participant attended.
     *
     * The participant dashboard and the attendance screen both print "attended 8
     * of 12 sessions", and neither may derive those counts from the percentage
     * — rounding would make the sentence disagree with the ring above it. The
     * counting rule stays here, next to the rate it produces (art. 6).
     *
     * @return array{total: int, attended: int}
     */
    public function sessionCounts(User $user, Cohort $cohort, ?CarbonImmutable $at = null): array
    {
        $sessionIds = $this->countableSessionIds($cohort, $at ?? Clock::now());
        $total = count($sessionIds);

        if ($total === 0) {
            return ['total' => 0, 'attended' => 0];
        }

        $attended = DB::table('attendances')
            ->whereIn('session_id', $sessionIds)
            ->where('user_id', $user->getKey())
            ->whereIn('status', AttendanceCounting::countedAsAttendedValues())
            ->count();

        return ['total' => $total, 'attended' => $attended];
    }

    /**
     * How many finished sessions carry each attendance status for this
     * participant, plus how many they simply never recorded. The attendance
     * screen prints the six-line summary of PRD §9.9.6 from this.
     *
     * @return array<string, int>
     */
    public function statusCounts(User $user, Cohort $cohort, ?CarbonImmutable $at = null): array
    {
        $sessionIds = $this->countableSessionIds($cohort, $at ?? Clock::now());

        $counts = [];

        foreach (AttendanceStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        if ($sessionIds === []) {
            return $counts;
        }

        $rows = DB::table('attendances')
            ->whereIn('session_id', $sessionIds)
            ->where('user_id', $user->getKey())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $recorded = 0;

        foreach ($rows as $row) {
            $value = (string) $row->status;
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($value, $counts)) {
                $counts[$value] = $aggregate;
            }

            $recorded += $aggregate;
        }

        // BR-08 — a finished session with no record at all is an absence. The
        // reconciliation job writes those rows; until it has run for the newest
        // session the screen must still say the truth.
        $counts[AttendanceStatus::Absent->value] += max(0, count($sessionIds) - $recorded);

        return $counts;
    }

    /**
     * The cohort's attendance threshold, defaulting to the documented 75%.
     */
    public function minAttendanceRate(Cohort $cohort): float
    {
        $value = $cohort->getAttribute('min_attendance_rate');

        return is_numeric($value) ? (float) $value : self::DEFAULT_MIN_ATTENDANCE_RATE;
    }

    /**
     * Everything the certificate screen and the admin bulk-issue list need.
     *
     * @return array{
     *     enrolled: bool,
     *     attendance_rate: float,
     *     required_attendance_rate: float,
     *     meets_attendance: bool,
     *     final_score: float,
     *     required_score: float,
     *     meets_score: bool,
     *     eligible: bool,
     *     reasons: array<string, string>
     * }
     */
    public function summary(User $user, Cohort $cohort): array
    {
        $enrolled = $this->isEnrolled($user, $cohort);
        $rate = $this->attendanceRate($user, $cohort);
        $requiredRate = $this->minAttendanceRate($cohort);
        $score = $this->scores->finalScore($user, $cohort);
        $requiredScore = $this->scores->passScore($cohort);

        $meetsAttendance = $rate >= $requiredRate;
        $meetsScore = $score >= $requiredScore;

        return [
            'enrolled' => $enrolled,
            'attendance_rate' => $rate,
            'required_attendance_rate' => $requiredRate,
            'meets_attendance' => $meetsAttendance,
            'final_score' => $score,
            'required_score' => $requiredScore,
            'meets_score' => $meetsScore,
            'eligible' => $enrolled && $meetsAttendance && $meetsScore,
            'reasons' => $this->reasons($user, $cohort),
        ];
    }

    public function isEnrolled(User $user, Cohort $cohort): bool
    {
        return Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('user_id', $user->getKey())
            ->where('role_in_cohort', EnrollmentRole::Participant)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->exists();
    }

    /**
     * Non-cancelled sessions of the cohort that have already ended.
     *
     * @return list<string>
     */
    private function countableSessionIds(Cohort $cohort, CarbonImmutable $at): array
    {
        $sessions = Session::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', '!=', SessionStatus::Cancelled)
            ->get(['id', 'date', 'start_time', 'end_time', 'status']);

        $ids = [];

        foreach ($sessions as $session) {
            if ($this->window->hasEnded($session, $at)) {
                $ids[] = (string) $session->getKey();
            }
        }

        return $ids;
    }

    /**
     * Latin digits, at most two decimals, no trailing zeros.
     */
    private function number(float $value): string
    {
        $formatted = number_format(round($value, self::PRECISION), self::PRECISION, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }
}
