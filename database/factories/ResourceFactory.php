<?php

declare(strict_types=1);

/**
 * Training-kit material. Files live outside the web root; `file_url` holds the
 * storage path, not a public address, and access always goes through a
 * 15-minute signed link (Constitution art. 22).
 *
 * @see PRD §7.6 · PROJECT-CONTRACT §4, §11
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resource>
 */
final class ResourceFactory extends Factory
{
    /** @var class-string<Resource> */
    protected $model = Resource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cohort_id' => Cohort::factory(),
            'week_id' => null,
            'session_id' => null,
            'title' => fake('en_US')->words(3, true),
            'description' => fake('en_US')->sentence(),
            'type' => 'file',
            'file_url' => 'resources/'.fake()->uuid().'.pdf',
            'external_url' => null,
            'size' => fake()->numberBetween(50_000, 4_000_000),
            'uploaded_by' => User::factory()->trainer(),
            'download_count' => 0,
        ];
    }

    public function link(string $url = 'https://athar-dev.edu.sa'): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'link',
            'file_url' => null,
            'external_url' => $url,
            'size' => null,
        ]);
    }

    public function video(string $url = 'https://athar-dev.edu.sa/video'): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'video',
            'file_url' => null,
            'external_url' => $url,
            'size' => null,
        ]);
    }
}
