<?php

declare(strict_types=1);

/**
 * Landing page settings — the changeable public content, held in the database
 * and edited from the admin panel (BR-31, BR-36).
 *
 * @see PRD §7.6, §9.1 · BR-31, BR-36 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\LandingSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LandingSetting>
 */
final class LandingSettingFactory extends Factory
{
    /** @var class-string<LandingSetting> */
    protected $model = LandingSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            'seats_remaining_override' => null,
            'countdown_enabled' => true,
            'hero_text' => null,
            'faq' => [],
            'is_registration_open' => true,
        ];
    }

    public function registrationClosed(): self
    {
        return $this->state(fn (array $attributes): array => ['is_registration_open' => false]);
    }

    public function withoutCountdown(): self
    {
        return $this->state(fn (array $attributes): array => ['countdown_enabled' => false]);
    }
}
