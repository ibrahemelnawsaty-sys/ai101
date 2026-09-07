<?php

declare(strict_types=1);

/**
 * Digital participant cards. `qr_token` is long and random because the
 * verification page it opens is public (BR-25).
 *
 * @see PRD §7.6, §9.6 · BR-25 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\DigitalCard;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DigitalCard>
 */
final class DigitalCardFactory extends Factory
{
    /** @var class-string<DigitalCard> */
    protected $model = DigitalCard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'cohort_id' => Cohort::factory(),
            'card_number' => 'AI101-'.fake()->unique()->numerify('#####'),
            'qr_token' => Str::lower(Str::random(64)),
            'issued_at' => Clock::now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn (array $attributes): array => ['revoked_at' => Clock::now()]);
    }
}
