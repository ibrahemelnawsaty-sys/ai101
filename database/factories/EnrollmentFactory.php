<?php

declare(strict_types=1);

/**
 * Cohort membership.
 *
 * @see PRD §7.2 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
final class EnrollmentFactory extends Factory
{
    /** @var class-string<Enrollment> */
    protected $model = Enrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            'user_id' => User::factory(),
            'role_in_cohort' => 'participant',
            'enrolled_at' => Clock::now(),
            'status' => 'active',
            'final_score' => null,
            'attendance_rate' => null,
        ];
    }

    public function trainer(): self
    {
        return $this->state(fn (array $attributes): array => ['role_in_cohort' => 'trainer']);
    }

    public function pending(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'pending']);
    }

    public function withdrawn(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'withdrawn']);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'completed']);
    }
}
