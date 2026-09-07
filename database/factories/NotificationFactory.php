<?php

declare(strict_types=1);

/**
 * In-app notifications. Preview mode must never mark one as read (BR-34).
 *
 * @see PRD §7.6 · BR-34 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
final class NotificationFactory extends Factory
{
    /** @var class-string<Notification> */
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'session_reminder',
            'title' => fake('en_US')->sentence(5),
            'body' => fake('en_US')->sentence(12),
            'link' => '/dashboard',
            'is_read' => false,
            'read_at' => null,
            'channel' => 'in_app',
        ];
    }

    public function read(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_read' => true,
            'read_at' => Clock::now(),
        ]);
    }

    public function ofType(string $type): self
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }
}
