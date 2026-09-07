<?php

declare(strict_types=1);

/**
 * A participant's state on one journey step. Derived from real data by the
 * JourneyEvaluator — never set by the participant (BR-21).
 *
 * @see PRD §7.6, §9.7 · BR-21 · PROJECT-CONTRACT §4, §9
 */

namespace Database\Factories;

use App\Models\JourneyStep;
use App\Models\User;
use App\Models\UserJourneyState;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserJourneyState>
 */
final class UserJourneyStateFactory extends Factory
{
    /** @var class-string<UserJourneyState> */
    protected $model = UserJourneyState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'journey_step_id' => JourneyStep::factory(),
            'status' => 'locked',
            'completed_at' => null,
        ];
    }

    public function current(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'current']);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'completed',
            'completed_at' => Clock::now(),
        ]);
    }
}
