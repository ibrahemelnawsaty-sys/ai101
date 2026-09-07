<?php

declare(strict_types=1);

/**
 * Per-type notification preferences.
 *
 * @see PRD §7.6 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
final class NotificationPreferenceFactory extends Factory
{
    /** @var class-string<NotificationPreference> */
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'session_reminder',
            'in_app_enabled' => true,
            'email_enabled' => true,
        ];
    }

    public function ofType(string $type): self
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }

    public function silenced(): self
    {
        return $this->state(fn (array $attributes): array => [
            'in_app_enabled' => false,
            'email_enabled' => false,
        ]);
    }
}
