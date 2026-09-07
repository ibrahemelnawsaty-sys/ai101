<?php

declare(strict_types=1);

/**
 * Grades. `feedback` is always at least 10 characters — the database CHECK
 * rejects anything shorter (BR-13), so a factory must never produce one.
 *
 * @see PRD §7.5 · BR-12, BR-13, BR-14 · PROJECT-CONTRACT §4, §7
 */

namespace Database\Factories;

use App\Models\Evaluation;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evaluation>
 */
final class EvaluationFactory extends Factory
{
    /** @var class-string<Evaluation> */
    protected $model = Evaluation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_type' => 'assignment',
            'entity_id' => Submission::factory(),
            'user_id' => User::factory(),
            'score' => 10.00,
            'max_score' => 12.00,
            'feedback' => fake('en_US')->paragraph(2),
            'evaluated_by' => User::factory()->trainer(),
            'evaluated_at' => Clock::now(),
            'revision_reason' => null,
        ];
    }

    public function forSubmission(Submission $submission, float $score, float $maxScore): self
    {
        return $this->state(fn (array $attributes): array => [
            'entity_type' => 'assignment',
            'entity_id' => $submission->getKey(),
            'user_id' => $submission->getAttribute('user_id'),
            'score' => $score,
            'max_score' => $maxScore,
        ]);
    }

    public function forProjectSubmission(ProjectSubmission $submission, float $score, float $maxScore): self
    {
        return $this->state(fn (array $attributes): array => [
            'entity_type' => 'final_project',
            'entity_id' => $submission->getKey(),
            'user_id' => $submission->getAttribute('user_id'),
            'score' => $score,
            'max_score' => $maxScore,
        ]);
    }

    public function score(float $score, float $maxScore): self
    {
        return $this->state(fn (array $attributes): array => [
            'score' => $score,
            'max_score' => $maxScore,
        ]);
    }

    /**
     * A grade that has been revised after being recorded — BR-14 makes the
     * reason mandatory.
     */
    public function revised(string $reason): self
    {
        return $this->state(fn (array $attributes): array => ['revision_reason' => $reason]);
    }
}
