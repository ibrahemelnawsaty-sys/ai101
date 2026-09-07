<?php

declare(strict_types=1);

/**
 * Attendance for every session that has actually finished.
 *
 * A cancelled session gets no attendance rows at all — it never happened, so it
 * must not appear in anyone's denominator (PRD §7.8).
 *
 * The pattern is deterministic rather than random, on purpose:
 *  - a small, fixed group attends roughly a third of the sessions and therefore
 *    falls below the 75% threshold, so the "not eligible for a certificate"
 *    path has real data behind it (BR-26);
 *  - the remainder land on present / late / absent / excused / incomplete, so
 *    every AttendanceStatus exists in the seeded database;
 *  - one slice of rows is a manual trainer correction, carrying `is_manual`,
 *    `edited_by` and a mandatory `edit_reason`.
 *
 * Check-in and check-out instants are composed from the session's Riyadh
 * wall-clock date and time, exactly as the AttendanceWindow does (BR-07).
 *
 * @see PRD §7.4, §7.8 · BR-01 … BR-09, BR-26 · PROJECT-CONTRACT §4, §6
 */

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Session;
use App\Models\User;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AttendanceSeeder extends Seeder
{
    /** Minutes before the start a punctual participant checks in. */
    private const EARLY_CHECK_IN_MINUTES = 12;

    /** Minutes after the start a late participant checks in — past the BR-03 boundary. */
    private const LATE_CHECK_IN_MINUTES = 45;

    /** Minutes before the end a participant checks out. */
    private const CHECK_OUT_BEFORE_END_MINUTES = 4;

    public function run(): void
    {
        $cohort = Cohort::query()->firstOrFail();

        $sessions = $this->finishedSessions($cohort);

        if ($sessions->isEmpty()) {
            return;
        }

        $participants = $this->activeParticipants($cohort);

        $trainer = User::query()
            ->where('role', 'trainer')
            ->orderBy('email')
            ->firstOrFail();

        /** @var array<string, string> $reasons */
        $reasons = SeedContent::section('attendance');

        DB::transaction(function () use ($sessions, $participants, $trainer, $reasons): void {
            foreach ($sessions as $ordinal => $session) {
                $start = SeedContent::sessionInstant(
                    $session->getAttribute('date'),
                    $session->getAttribute('start_time'),
                );

                $end = SeedContent::sessionInstant(
                    $session->getAttribute('date'),
                    $session->getAttribute('end_time'),
                );

                foreach ($participants as $index => $participant) {
                    $this->recordAttendance(
                        session: $session,
                        participant: $participant,
                        trainer: $trainer,
                        reasons: $reasons,
                        index: (int) $index,
                        ordinal: (int) $ordinal,
                        start: $start,
                        end: $end,
                    );
                }
            }
        });
    }

    /**
     * Sessions whose end instant is already in the past and that were not
     * cancelled. Compared against Clock, never against a stored flag (BR-07).
     *
     * @return Collection<int, Session>
     */
    private function finishedSessions(Cohort $cohort): Collection
    {
        $now = Clock::now();

        /** @var Collection<int, Session> $sessions */
        $sessions = Session::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', '!=', 'cancelled')
            ->orderBy('date')
            ->get();

        return $sessions
            ->filter(static fn (Session $session): bool => SeedContent::sessionInstant(
                $session->getAttribute('date'),
                $session->getAttribute('end_time'),
            )->lessThan($now))
            ->values();
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

    /**
     * @param  array<string, string>  $reasons
     */
    private function recordAttendance(
        Session $session,
        User $participant,
        User $trainer,
        array $reasons,
        int $index,
        int $ordinal,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): void {
        $status = $this->classify($index, $ordinal);

        // A trainer correction never rescues the deliberately poor attenders —
        // the "below 75%" path must survive it (BR-26).
        $isCorrection = $status === 'absent'
            && $index % 19 !== 0
            && $index % 7 === 3;

        if ($isCorrection) {
            $status = 'present';
        }

        $checkIn = match ($status) {
            'present' => $start->subMinutes(self::EARLY_CHECK_IN_MINUTES),
            'late' => $start->addMinutes(self::LATE_CHECK_IN_MINUTES),
            'incomplete' => $start->subMinutes(5),
            default => null,
        };

        $checkOut = in_array($status, ['present', 'late'], true)
            ? $end->subMinutes(self::CHECK_OUT_BEFORE_END_MINUTES)
            : null;

        $editReason = match (true) {
            $isCorrection => $reasons['manual_edit_reason'],
            $status === 'excused' => $reasons['excuse_reason'],
            default => null,
        };

        Attendance::factory()->create([
            'session_id' => $session->getKey(),
            'user_id' => $participant->getKey(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'status' => $status,
            'is_manual' => $editReason !== null,
            'edited_by' => $editReason === null ? null : $trainer->getKey(),
            'edit_reason' => $editReason,
        ]);
    }

    /**
     * Deterministic attendance behaviour, so the same seed always produces the
     * same eligibility picture.
     */
    private function classify(int $index, int $ordinal): string
    {
        // A fixed group that attends about a third of the sessions and so falls
        // below the 75% requirement (BR-26).
        if ($index % 19 === 0) {
            return $ordinal % 3 === 0 ? 'present' : 'absent';
        }

        // Every modulus here is larger than the number of sessions held, so a
        // participant collects at most one of each exception. That is what keeps
        // ordinary attendance in the 90–100% band while still putting every
        // AttendanceStatus in the database.
        return match (true) {
            (($index * 3) + $ordinal) % 47 === 0 => 'excused',
            (($index * 5) + $ordinal) % 43 === 0 => 'incomplete',
            ($index + $ordinal) % 13 === 0 => 'absent',
            ($index + $ordinal) % 7 === 0 => 'late',
            default => 'present',
        };
    }
}
