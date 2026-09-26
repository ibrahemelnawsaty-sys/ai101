<?php

declare(strict_types=1);

/**
 * Final project submissions. Versioned exactly like assignment submissions (BR-19).
 *
 * The hand-in lives in `answers` since D-121, written through
 * SubmissionFields::answer() like the real submit writes it; the earlier fixed
 * columns (`live_url`, `presentation_file` …) are never written any more, so
 * the factory leaves them empty too.
 *
 * @see PRD §7.6 · BR-19 · PROJECT-CONTRACT §4 · D-121
 */

namespace Database\Factories;

use App\Enums\SubmissionFieldType;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\FinalProject\SubmissionFields;
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
            'answers' => [
                SubmissionFields::answer(null, SubmissionFieldType::Url, 'Live project link', 'https://ai101-final.example.test'),
                SubmissionFields::answer(null, SubmissionFieldType::Github, 'Public GitHub repository', 'https://github.com/athar-trainee/ai101-final'),
            ],
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
