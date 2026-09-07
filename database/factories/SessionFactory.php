<?php

declare(strict_types=1);

/**
 * Live sessions.
 *
 * `date`, `start_time` and `end_time` are Asia/Riyadh wall-clock values, not
 * instants (PRD §7.3). Anything that needs an instant composes them through
 * App\Services\Time\Clock. Attendance-window tests set the clock with
 * Clock::fake() and leave the session fixed — never the other way round.
 *
 * @see PRD §7.3 · BR-01 … BR-09 · PROJECT-CONTRACT §4, §6
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Session;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Session>
 */
final class SessionFactory extends Factory
{
    /** @var class-string<Session> */
    protected $model = Session::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            'week_id' => null,
            'title' => fake('en_US')->words(3, true),
            'topic' => fake('en_US')->sentence(6),
            'type' => 'training',
            'date' => Clock::riyadh()->format('Y-m-d'),
            'start_time' => '19:00:00',
            'end_time' => '21:30:00',
            'trainer_id' => null,
            'zoom_url' => null,
            'zoom_passcode' => null,
            'recording_url' => null,
            'status' => 'scheduled',
            'cancellation_reason' => null,
        ];
    }

    public function intro(): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'intro',
            'end_time' => '21:00:00',
        ]);
    }

    public function closing(): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'closing',
            'end_time' => '20:30:00',
        ]);
    }

    public function project(): self
    {
        return $this->state(fn (array $attributes): array => ['type' => 'project']);
    }

    /**
     * A session on a given Riyadh calendar day, keeping the standard 19:00–21:30 slot.
     */
    public function onDay(string $riyadhDate): self
    {
        return $this->state(fn (array $attributes): array => ['date' => $riyadhDate]);
    }

    public function withTimes(string $startTime, string $endTime): self
    {
        return $this->state(fn (array $attributes): array => [
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    public function past(int $daysAgo = 7): self
    {
        return $this->state(fn (array $attributes): array => [
            'date' => Clock::riyadh()->subDays($daysAgo)->format('Y-m-d'),
            'status' => 'completed',
        ]);
    }

    public function upcoming(int $daysAhead = 7): self
    {
        return $this->state(fn (array $attributes): array => [
            'date' => Clock::riyadh()->addDays($daysAhead)->format('Y-m-d'),
            'status' => 'scheduled',
        ]);
    }

    public function live(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'live']);
    }

    public function cancelled(string $reason = 'Cancelled by the training centre.'): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
        ]);
    }
}
