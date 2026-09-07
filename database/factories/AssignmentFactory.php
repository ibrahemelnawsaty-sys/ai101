<?php

declare(strict_types=1);

/**
 * Assignments. The four published assignments of a cohort sum to 50 marks
 * (BR-11); a factory-made assignment defaults to a 12.5-free integer value and
 * the seeder sets the real distribution.
 *
 * @see PRD §7.5 · BR-11, BR-12 · PROJECT-CONTRACT §4, §7
 */

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
final class AssignmentFactory extends Factory
{
    /** @var class-string<Assignment> */
    protected $model = Assignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            'week_id' => null,
            'title' => fake('en_US')->words(3, true),
            'description' => fake('en_US')->paragraph(),
            'is_mandatory' => true,
            'max_score' => 12,
            'due_at' => Clock::now()->addDays(7),
            'allow_late' => false,
            'allow_github_link' => true,
            'max_file_size_mb' => 10,
            'max_files' => 3,
            'attachments' => [],
            'status' => 'published',
            'created_by' => User::factory()->trainer(),
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'draft']);
    }

    public function published(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'published']);
    }

    public function optional(): self
    {
        return $this->state(fn (array $attributes): array => ['is_mandatory' => false]);
    }

    public function maxScore(int $score): self
    {
        return $this->state(fn (array $attributes): array => ['max_score' => $score]);
    }

    public function dueInThePast(int $daysAgo = 3): self
    {
        return $this->state(fn (array $attributes): array => [
            'due_at' => Clock::now()->subDays($daysAgo),
        ]);
    }

    public function allowingLate(): self
    {
        return $this->state(fn (array $attributes): array => ['allow_late' => true]);
    }
}
