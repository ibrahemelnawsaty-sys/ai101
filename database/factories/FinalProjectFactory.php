<?php

declare(strict_types=1);

/**
 * The closing project — 50 of the 100 marks (BR-11). Locked until a trainer or
 * admin unlocks it.
 *
 * @see PRD §7.6 · BR-11 · PROJECT-CONTRACT §4, §7
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinalProject>
 */
final class FinalProjectFactory extends Factory
{
    /** @var class-string<FinalProject> */
    protected $model = FinalProject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            'title' => fake('en_US')->words(3, true),
            'brief' => fake('en_US')->paragraph(),
            'requirements' => [],
            'is_unlocked' => false,
            'unlocked_at' => null,
            'unlocked_by' => null,
            'due_at' => Clock::now()->addDays(14),
            'max_score' => 50,
            'attachments' => [],
        ];
    }

    public function unlocked(?User $by = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_unlocked' => true,
            'unlocked_at' => Clock::now(),
            'unlocked_by' => $by?->getKey() ?? User::factory()->trainer(),
        ]);
    }

    public function dueInThePast(int $daysAgo = 2): self
    {
        return $this->state(fn (array $attributes): array => [
            'due_at' => Clock::now()->subDays($daysAgo),
        ]);
    }
}
