<?php

declare(strict_types=1);

/**
 * Final project submissions. Versioned exactly like assignment submissions (BR-19).
 *
 * @see PRD §7.6 · BR-19 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectSubmission>
 */
final class ProjectSubmissionFactory extends Factory
{
    /** @var class-string<ProjectSubmission> */
    protected $model = ProjectSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'final_project_id' => FinalProject::factory(),
            'user_id' => User::factory(),
            'files' => [],
            'live_url' => 'https://ai101-final.example.test',
            'github_url' => 'https://github.com/athar-trainee/ai101-final',
            'presentation_file' => [
                'original_name' => 'presentation.pdf',
                'path' => 'final-projects/'.fake()->uuid().'.pdf',
                'size' => fake()->numberBetween(200_000, 5_000_000),
                'mime' => 'application/pdf',
            ],
            'logo_file' => null,
            'description' => fake('en_US')->paragraph(),
            'submitted_at' => Clock::now(),
            'is_late' => false,
            'version' => 1,
            'status' => 'submitted',
        ];
    }

    public function late(): self
    {
        return $this->state(fn (array $attributes): array => ['is_late' => true]);
    }

    public function graded(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'graded']);
    }

    public function version(int $version): self
    {
        return $this->state(fn (array $attributes): array => ['version' => $version]);
    }
}
