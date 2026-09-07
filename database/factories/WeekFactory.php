<?php

declare(strict_types=1);

/**
 * Programme weeks, 1 … 4.
 *
 * @see PRD §7.3 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Week;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Week>
 */
final class WeekFactory extends Factory
{
    /** @var class-string<Week> */
    protected $model = Week::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Clock::riyadh()->startOfDay();

        return [
            'cohort_id' => Cohort::factory(),
            'index' => 1,
            'title' => fake('en_US')->words(3, true),
            'objectives' => [],
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $start->addDays(6)->format('Y-m-d'),
        ];
    }

    public function index(int $index): self
    {
        return $this->state(fn (array $attributes): array => ['index' => $index]);
    }
}
