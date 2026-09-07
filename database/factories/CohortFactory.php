<?php

declare(strict_types=1);

/**
 * Cohorts. The two certificate thresholds default to the values fixed by the
 * contract: pass_score 60, min_attendance_rate 75 (BR-26).
 *
 * @see PRD §7.2 · BR-26 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Program;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cohort>
 */
final class CohortFactory extends Factory
{
    /** @var class-string<Cohort> */
    protected $model = Cohort::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Clock::riyadh()->startOfDay();

        return [
            'program_id' => Program::factory(),
            'name' => fake('en_US')->words(2, true),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $start->addWeeks(4)->format('Y-m-d'),
            'capacity' => 60,
            'registration_closes_at' => $start->subDays(1),
            'seats_taken' => 0,
            'status' => 'running',
            'pass_score' => 60,
            'min_attendance_rate' => 75,
        ];
    }

    public function upcoming(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'upcoming']);
    }

    public function open(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'open']);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'completed']);
    }

    public function thresholds(int $passScore, int $minAttendanceRate): self
    {
        return $this->state(fn (array $attributes): array => [
            'pass_score' => $passScore,
            'min_attendance_rate' => $minAttendanceRate,
        ]);
    }
}
