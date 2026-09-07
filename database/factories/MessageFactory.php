<?php

declare(strict_types=1);

/**
 * Messages inside a thread.
 *
 * @see PRD §7.6 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    /** @var class-string<Message> */
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'sender_id' => User::factory(),
            'body' => fake('en_US')->paragraph(),
            'attachments' => [],
            'sent_at' => Clock::now(),
            'edited_at' => null,
        ];
    }

    public function edited(): self
    {
        return $this->state(fn (array $attributes): array => ['edited_at' => Clock::now()]);
    }
}
