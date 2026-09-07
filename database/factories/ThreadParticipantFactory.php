<?php

declare(strict_types=1);

/**
 * Thread membership. `last_read_at` is the unread counter's only source and
 * preview mode must never touch it (BR-34).
 *
 * @see PRD §7.6 · BR-34 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadParticipant>
 */
final class ThreadParticipantFactory extends Factory
{
    /** @var class-string<ThreadParticipant> */
    protected $model = ThreadParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'user_id' => User::factory(),
            'last_read_at' => null,
            'is_muted' => false,
        ];
    }

    public function read(): self
    {
        return $this->state(fn (array $attributes): array => ['last_read_at' => Clock::now()]);
    }

    public function muted(): self
    {
        return $this->state(fn (array $attributes): array => ['is_muted' => true]);
    }
}
