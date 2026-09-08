<?php

declare(strict_types=1);

/**
 * Training programmes.
 *
 * @see PRD §7.2 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Program>
 */
final class ProgramFactory extends Factory
{
    /** @var class-string<Program> */
    protected $model = Program::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words() is declared as array|string whatever its $asText argument, so the
        // three words are joined here rather than asked for as one string.
        $words = fake('en_US')->unique()->words(3);
        $name = is_array($words) ? implode(' ', $words) : $words;

        return [
            'name_ar' => fake('ar_SA')->company(),
            'name_en' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'description' => fake('en_US')->paragraph(),
            'banner_url' => null,
            'objectives' => [],
            'target_audience' => [],
            'certificates' => [],
            'status' => 'published',
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'draft']);
    }

    public function archived(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'archived']);
    }
}
