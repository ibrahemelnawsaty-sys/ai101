<?php

declare(strict_types=1);

/**
 * Single-use e-mail tokens for verification and password reset. Only the hash
 * is stored — the raw token never reaches the database.
 *
 * @see PRD §7.6, §9.3 · BR-29, BR-30 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\EmailToken;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailToken>
 */
final class EmailTokenFactory extends Factory
{
    /** @var class-string<EmailToken> */
    protected $model = EmailToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'type' => 'verify',
            'expires_at' => Clock::now()->addHours(24),
            'used_at' => null,
        ];
    }

    public function reset(): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'reset',
            'expires_at' => Clock::now()->addHour(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => Clock::now()->subMinute(),
        ]);
    }

    public function used(): self
    {
        return $this->state(fn (array $attributes): array => ['used_at' => Clock::now()]);
    }
}
