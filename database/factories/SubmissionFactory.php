<?php

declare(strict_types=1);

/**
 * Assignment submissions. A resubmission is a new row with a higher `version`;
 * the earlier version is never removed (BR-19).
 *
 * @see PRD §7.5 · BR-19 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
final class SubmissionFactory extends Factory
{
    /** @var class-string<Submission> */
    protected $model = Submission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'user_id' => User::factory(),
            'files' => [[
                'name' => 'submission.pdf',
                'path' => 'submissions/'.fake()->uuid().'.pdf',
                'size' => fake()->numberBetween(20_000, 900_000),
                'mime' => 'application/pdf',
            ]],
            'github_url' => null,
            'note' => null,
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

    public function underReview(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'under_review']);
    }

    public function graded(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'graded']);
    }

    public function version(int $version): self
    {
        return $this->state(fn (array $attributes): array => ['version' => $version]);
    }

    public function withGithub(string $url = 'https://github.com/athar-trainee/ai101-task'): self
    {
        return $this->state(fn (array $attributes): array => ['github_url' => $url]);
    }
}
