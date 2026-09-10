<?php

declare(strict_types=1);

/**
 * The fourteen sessions of the cohort: one introductory meeting, twelve
 * training sessions on Saturday/Monday/Wednesday 17:00–19:00 Riyadh, and the
 * closing ceremony.
 *
 * One week-three session is cancelled with a reason, because PRD §7.8 forbids
 * deleting a session and every screen needs the cancelled state to exist.
 *
 * Status is decided by comparing the session's real end instant to Clock::now()
 * — never by a hard-coded flag, so a re-seed on any day stays truthful (BR-07).
 * `zoom_url` is stored for sessions that have not finished; a finished session
 * carries a recording instead.
 *
 * @see PRD §7.3, §7.8 · BR-07 · PROJECT-CONTRACT §4, §6
 */

namespace Database\Seeders;

use App\Models\Cohort;
use App\Models\Session;
use App\Models\User;
use App\Models\Week;
use App\Services\Time\Clock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

final class ScheduleSeeder extends Seeder
{
    /** The training-session ordinal (0-based) that is cancelled. Week 3, Tuesday. */
    private const CANCELLED_ORDINAL = 7;

    public function run(): void
    {
        $cohort = Cohort::query()->firstOrFail();

        /** @var Collection<int, User> $trainers */
        $trainers = User::query()
            ->where('role', 'trainer')
            ->orderBy('email')
            ->get();

        /** @var Collection<int, Week> $weeks */
        $weeks = Week::query()
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('index')
            ->get()
            ->keyBy('index');

        $this->createIntro($cohort, $trainers);
        $this->createTrainingSessions($cohort, $weeks, $trainers);
        $this->createClosing($cohort, $trainers);
    }

    /**
     * @param  Collection<int, User>  $trainers
     */
    private function createIntro(Cohort $cohort, Collection $trainers): void
    {
        /** @var array<string, mixed> $sessions */
        $sessions = SeedContent::section('sessions');

        /** @var array<string, string> $intro */
        $intro = $sessions['intro'];

        $this->createSession(
            cohort: $cohort,
            weekId: null,
            title: $intro['title'],
            topic: $intro['topic'],
            type: 'intro',
            dayOffset: SeedContent::INTRO_DAY,
            endTime: SeedContent::INTRO_END_TIME,
            trainer: $trainers->first(),
            cancelled: false,
        );
    }

    /**
     * @param  Collection<int, Week>  $weeks
     * @param  Collection<int, User>  $trainers
     */
    private function createTrainingSessions(Cohort $cohort, Collection $weeks, Collection $trainers): void
    {
        /** @var array<string, mixed> $sessions */
        $sessions = SeedContent::section('sessions');

        /** @var list<array<string, string>> $training */
        $training = $sessions['training'];

        foreach (SeedContent::trainingSessionDays() as $ordinal => $dayOffset) {
            $week = $weeks->get(SeedContent::weekOfTrainingSession($ordinal));

            $this->createSession(
                cohort: $cohort,
                weekId: $week?->getKey(),
                title: $training[$ordinal]['title'],
                topic: $training[$ordinal]['topic'],
                type: 'training',
                dayOffset: $dayOffset,
                endTime: SeedContent::SESSION_END_TIME,
                trainer: $trainers->get($ordinal % max($trainers->count(), 1)),
                cancelled: $ordinal === self::CANCELLED_ORDINAL,
            );
        }
    }

    /**
     * @param  Collection<int, User>  $trainers
     */
    private function createClosing(Cohort $cohort, Collection $trainers): void
    {
        /** @var array<string, mixed> $sessions */
        $sessions = SeedContent::section('sessions');

        /** @var array<string, string> $closing */
        $closing = $sessions['closing'];

        $this->createSession(
            cohort: $cohort,
            weekId: null,
            title: $closing['title'],
            topic: $closing['topic'],
            type: 'closing',
            dayOffset: SeedContent::CLOSING_DAY,
            endTime: SeedContent::CLOSING_END_TIME,
            trainer: $trainers->first(),
            cancelled: false,
        );
    }

    private function createSession(
        Cohort $cohort,
        ?string $weekId,
        string $title,
        string $topic,
        string $type,
        int $dayOffset,
        string $endTime,
        ?User $trainer,
        bool $cancelled,
    ): void {
        /** @var array<string, string> $extras */
        $extras = SeedContent::section('session_extras');

        $hasFinished = SeedContent::instant($dayOffset, $endTime)->lessThan(Clock::now());

        $status = match (true) {
            $cancelled => 'cancelled',
            $hasFinished => 'completed',
            default => 'scheduled',
        };

        Session::factory()->create([
            'cohort_id' => $cohort->getKey(),
            'week_id' => $weekId,
            'title' => $title,
            'topic' => $topic,
            'type' => $type,
            'date' => SeedContent::dateOn($dayOffset),
            'start_time' => SeedContent::SESSION_START_TIME,
            'end_time' => $endTime,
            'trainer_id' => $trainer?->getKey(),
            'zoom_url' => $status === 'scheduled'
                ? 'https://zoom.us/j/'.random_int(10_000_000_000, 99_999_999_999)
                : null,
            'zoom_passcode' => $status === 'scheduled' ? $extras['zoom_passcode'] : null,
            'recording_url' => $status === 'completed'
                ? 'https://athar-dev.edu.sa/recordings/'.SeedContent::dateOn($dayOffset)
                : null,
            'status' => $status,
            'cancellation_reason' => $cancelled ? $extras['cancellation_reason'] : null,
        ]);
    }
}
