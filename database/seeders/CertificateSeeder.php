<?php

declare(strict_types=1);

/**
 * Certificates for the participants who have clearly earned one.
 *
 * BR-26 requires BOTH conditions and allows no trade-off between them, and
 * CertificateEligibility is their only authority (Constitution art. 6). This
 * seeder therefore does NOT re-implement the rule: it issues only to
 * participants who clear both thresholds by a wide margin — 90% attendance
 * against a 75% requirement, and 75 marks against a 60 pass mark — so the
 * seeded rows agree with any correct implementation of the service rather than
 * competing with it. The margin is the whole point; a borderline seeded
 * certificate would be a second opinion on a rule that may only have one.
 *
 * Which statuses count as attended is NOT decided here either. That question is
 * open as D-26 (does `excused` count? does `incomplete`?), and the single place
 * the pending reading lives is App\Support\AttendanceCounting. This seeder
 * reads it rather than restating it: an earlier version hard-coded
 * ['present', 'late'], which was the opposite reading to the one the eligibility
 * service applies, and made the repository disagree with itself about a rule
 * nobody had ruled on.
 *
 * The serial number follows ATHAR-AI101-2026-0001 (PRD §9.17) with the year
 * taken from Clock, never written as a literal. `verify_code` is long and
 * random because the verification page is public (BR-25).
 *
 * @see PRD §7.6, §9.17 · BR-25, BR-26 · D-26 · Constitution art. 6 · PROJECT-CONTRACT §4, §8
 */

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\Notification;
use App\Models\Session;
use App\Models\User;
use App\Services\Time\Clock;
use App\Support\AttendanceCounting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CertificateSeeder extends Seeder
{
    /** Attendance percentage a seeded certificate must clear — well above the 75% rule. */
    private const SAFE_ATTENDANCE_RATE = 90.0;

    /** Total marks a seeded certificate must clear — well above the 60 pass mark. */
    private const SAFE_FINAL_SCORE = 75.0;

    /** How many certificates to issue, at most. */
    private const MAX_ISSUED = 12;

    public function run(): void
    {
        $cohort = Cohort::query()->firstOrFail();

        $admin = User::query()->where('role', 'admin')->orderBy('email')->first();

        $heldSessionIds = $this->heldSessionIds($cohort);

        if ($heldSessionIds->isEmpty()) {
            return;
        }

        $participants = $this->activeParticipants($cohort);
        $attended = $this->attendedCounts($heldSessionIds, $participants);
        $scores = $this->totalScores($participants);
        $heldCount = $heldSessionIds->count();

        $candidates = $participants
            ->map(static function (User $participant) use ($attended, $scores, $heldCount): array {
                $userId = (string) $participant->getKey();

                return [
                    'user' => $participant,
                    'attendance_rate' => round((($attended[$userId] ?? 0) / $heldCount) * 100, 2),
                    'final_score' => round($scores[$userId] ?? 0.0, 2),
                ];
            })
            ->filter(static fn (array $row): bool => $row['attendance_rate'] >= self::SAFE_ATTENDANCE_RATE
                && $row['final_score'] >= self::SAFE_FINAL_SCORE)
            ->sortByDesc('final_score')
            ->take(self::MAX_ISSUED)
            ->values();

        if ($candidates->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($candidates, $cohort, $admin): void {
            $year = Clock::riyadh()->format('Y');
            $serial = 0;

            foreach ($candidates as $row) {
                /** @var User $participant */
                $participant = $row['user'];
                $serial++;

                Certificate::factory()->create([
                    'user_id' => $participant->getKey(),
                    'cohort_id' => $cohort->getKey(),
                    'serial_number' => sprintf('ATHAR-AI101-%s-%04d', $year, $serial),
                    'verify_code' => Str::lower(Str::random(48)),
                    'issued_at' => Clock::now(),
                    'issued_by' => $admin?->getKey(),
                    'file_url' => null,
                    'final_score' => $row['final_score'],
                    'attendance_rate' => $row['attendance_rate'],
                    'revoked_at' => null,
                ]);

                $this->notify($participant);
            }
        });
    }

    private function notify(User $participant): void
    {
        /** @var list<array<string, string>> $templates */
        $templates = SeedContent::section('notifications');

        foreach ($templates as $template) {
            if ($template['type'] !== 'certificate_issued') {
                continue;
            }

            Notification::factory()->create([
                'user_id' => $participant->getKey(),
                'type' => $template['type'],
                'title' => $template['title'],
                'body' => $template['body'],
                'link' => $template['link'],
                'is_read' => false,
                'read_at' => null,
                'channel' => 'in_app',
            ]);

            return;
        }
    }

    /**
     * Sessions that actually took place: finished and not cancelled. A cancelled
     * session is never part of the denominator (PRD §7.8).
     *
     * @return Collection<int, string>
     */
    private function heldSessionIds(Cohort $cohort): Collection
    {
        $now = Clock::now();

        /** @var Collection<int, Session> $sessions */
        $sessions = Session::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', '!=', 'cancelled')
            ->get();

        return $sessions
            ->filter(static fn (Session $session): bool => SeedContent::sessionInstant(
                $session->getAttribute('date'),
                $session->getAttribute('end_time'),
            )->lessThan($now))
            ->map(static fn (Session $session): string => (string) $session->getKey())
            ->values();
    }

    /**
     * @param  Collection<int, string>  $heldSessionIds
     * @param  Collection<int, User>  $participants
     * @return array<string, int>
     */
    private function attendedCounts(Collection $heldSessionIds, Collection $participants): array
    {
        /** @var array<string, int> $counts */
        $counts = Attendance::query()
            ->whereIn('session_id', $heldSessionIds->all())
            ->whereIn('user_id', $participants->map(static fn (User $u): string => (string) $u->getKey())->all())
            ->whereIn('status', AttendanceCounting::countedAsAttendedValues())
            ->selectRaw('user_id, COUNT(*) as attended')
            ->groupBy('user_id')
            ->pluck('attended', 'user_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $counts;
    }

    /**
     * Total marks recorded against a participant across assignments and the
     * closing project.
     *
     * @param  Collection<int, User>  $participants
     * @return array<string, float>
     */
    private function totalScores(Collection $participants): array
    {
        /** @var array<string, float> $totals */
        $totals = Evaluation::query()
            ->whereIn('user_id', $participants->map(static fn (User $u): string => (string) $u->getKey())->all())
            ->selectRaw('user_id, SUM(score) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(static fn (mixed $value): float => (float) $value)
            ->all();

        return $totals;
    }

    /**
     * @return Collection<int, User>
     */
    private function activeParticipants(Cohort $cohort): Collection
    {
        $userIds = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', 'participant')
            ->where('status', 'active')
            ->pluck('user_id');

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('email')
            ->get();

        return $users->values();
    }
}
