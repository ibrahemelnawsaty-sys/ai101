<?php

declare(strict_types=1);

/**
 * Message threads.
 *
 * @see PRD §7.6, §9.13 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thread>
 */
final class ThreadFactory extends Factory
{
    /** @var class-string<Thread> */
    protected $model = Thread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            'type' => 'group',
            'title' => fake('en_US')->words(3, true),
            'created_by' => User::factory()->trainer(),
            'is_locked' => false,
        ];
    }

    public function trainerDm(): self
    {
        return $this->state(fn (array $attributes): array => ['type' => 'trainer_dm']);
    }

    public function announcement(): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'announcement',
            'is_locked' => true,
        ]);
    }
}
