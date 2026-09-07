<?php

declare(strict_types=1);

/**
 * The ten journey steps of a cohort. `unlock_rule` names the rule the
 * JourneyEvaluator applies; steps are never marked by hand (BR-21).
 *
 * @see PRD §7.6, §9.7 · BR-20, BR-21 · PROJECT-CONTRACT §4, §9
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\JourneyStep;
use App\Services\Journey\JourneyEvaluator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JourneyStep>
 */
final class JourneyStepFactory extends Factory
{
    /** @var class-string<JourneyStep> */
    protected $model = JourneyStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            'index' => 1,
            'title' => fake('en_US')->words(3, true),
            'description' => fake('en_US')->sentence(),
            'type' => 'enrollment',
            'unlock_rule' => JourneyEvaluator::RULE_ENROLLMENT,
            'related_entity_type' => null,
            'related_entity_id' => null,
            'icon' => 'flag',
        ];
    }

    public function index(int $index): self
    {
        return $this->state(fn (array $attributes): array => ['index' => $index]);
    }

    public function rule(string $type, string $unlockRule): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'unlock_rule' => $unlockRule,
        ]);
    }
}
