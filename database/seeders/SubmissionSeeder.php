<?php

declare(strict_types=1);

/**
 * Assignment submissions, closing-project submissions and the grades recorded
 * against them.
 *
 * Shape of the seeded data, chosen so no dashboard is ever empty and no
 * business rule is quietly broken:
 *  - about 92% of active participants submit each assignment, and three in four
 *    hand in the closing project; the rest leave a genuine gap for the
 *    "not submitted" state;
 *  - a fixed slice resubmits, producing version 1 AND version 2 rows — BR-19
 *    keeps both, and the grade attaches to the latest one;
 *  - about 9% of submissions stay `under_review` with no evaluation, so the
 *    trainer's grading queue has work in it;
 *  - the same fixed group that attends badly also scores below the pass mark,
 *    so a participant who fails on BOTH certificate conditions exists.
 *
 * Every evaluation carries a real feedback sentence: BR-13 makes it mandatory
 * at 10 characters or more, and the database CHECK rejects anything shorter.
 * `max_score` is snapshotted onto the evaluation so the score CHECK has a
 * ceiling to compare against (PRD §7.7).
 *
 * @see PRD §7.5, §7.6, §7.7 · BR-11, BR-12, BR-13, BR-19 · PROJECT-CONTRACT §4, §7
 */

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Evaluation;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use App\Models\User;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SubmissionSeeder extends Seeder
{
    /** Day offset on which the seeded project submissions were handed in. */
    private const PROJECT_SUBMITTED_DAY = 27;

    public function run(): void
    {
        $cohort = Cohort::query()->firstOrFail();
        $participants = $this->activeParticipants($cohort);

        if ($participants->isEmpty()) {
            return;
        }

        /** @var Collection<int, User> $trainers */
        $trainers = User::query()
            ->where('role', 'trainer')
            ->orderBy('email')
            ->get()
            ->values();

        DB::transaction(function () use ($cohort, $participants, $trainers): void {
            $this->seedAssignmentWork($cohort, $participants, $trainers);
            $this->seedProjectWork($cohort, $participants, $trainers);
        });
    }

    /**
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, User>  $trainers
     */
    private function seedAssignmentWork(Cohort $cohort, Collection $participants, Collection $trainers): void
    {
        $now = Clock::now();

        /** @var list<string> $feedbackPool */
        $feedbackPool = SeedContent::section('feedback')['assignment'];

        /** @var Collection<int, Assignment> $assignments */
        $assignments = Assignment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('status', 'published')
            ->orderBy('due_at')
            ->get()
            ->values();

        foreach ($assignments as $ordinal => $assignment) {
            $dueAt = $this->toInstant($assignment->getAttribute('due_at'));

            if ($dueAt->greaterThan($now)) {
                continue;
            }

            $maxScore = (float) $assignment->getAttribute('max_score');
            $allowsLate = (bool) $assignment->getAttribute('allow_late');

            foreach ($participants as $index => $participant) {
                $i = (int) $index;
                $a = (int) $ordinal;

                if (($i + $a) % 13 === 0) {
                    continue;
                }

                $isLate = $allowsLate && (($i * 3) + $a) % 11 === 0;
                $isGraded = ($i + $a) % 11 !== 0;
                $resubmitted = $i % 23 === 0;

                $submittedAt = $isLate
                    ? $dueAt->addHours(6)
                    : $dueAt->subHours(($i % 30) + 2);

                if ($resubmitted) {
                    Submission::factory()->create([
                        'assignment_id' => $assignment->getKey(),
                        'user_id' => $participant->getKey(),
                        'submitted_at' => $submittedAt->subHours(20),
                        'is_late' => false,
                        'version' => 1,
                        'status' => 'submitted',
                        'github_url' => null,
                    ]);
                }

                $submission = Submission::factory()->create([
                    'assignment_id' => $assignment->getKey(),
                    'user_id' => $participant->getKey(),
                    'submitted_at' => $submittedAt,
                    'is_late' => $isLate,
                    'version' => $resubmitted ? 2 : 1,
                    'status' => $isGraded ? 'graded' : 'under_review',
                    'github_url' => $i % 4 === 0
                        ? 'https://github.com/athar-trainee-'.($i + 1).'/ai101-task-'.($a + 1)
                        : null,
                ]);

                if (! $isGraded) {
                    continue;
                }

                $this->recordGrade(
                    entityType: 'assignment',
                    entityId: (string) $submission->getKey(),
                    participant: $participant,
                    trainer: $this->trainerFor($trainers, $i + $a),
                    score: $this->scoreFor($i, $a, $maxScore, isProject: false),
                    maxScore: $maxScore,
                    feedback: $feedbackPool[($i + $a) % count($feedbackPool)],
                    evaluatedAt: $this->clampToNow($submittedAt->addDays(3), $now),
                );
            }
        }
    }

    /**
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, User>  $trainers
     */
    private function seedProjectWork(Cohort $cohort, Collection $participants, Collection $trainers): void
    {
        $project = FinalProject::query()
            ->where('cohort_id', $cohort->getKey())
            ->first();

        if ($project === null) {
            return;
        }

        $now = Clock::now();
        $maxScore = (float) $project->getAttribute('max_score');
        $submittedAt = SeedContent::instant(self::PROJECT_SUBMITTED_DAY, '20:00:00');

        /** @var list<string> $feedbackPool */
        $feedbackPool = SeedContent::section('feedback')['final_project'];

        foreach ($participants as $index => $participant) {
            $i = (int) $index;

            // Three participants in every four have handed the project in.
            if ($i % 4 === 3) {
                continue;
            }

            $isGraded = $i % 7 !== 6;

            $submission = ProjectSubmission::factory()->create([
                'final_project_id' => $project->getKey(),
                'user_id' => $participant->getKey(),
                'submitted_at' => $submittedAt->subHours($i % 12),
                'is_late' => false,
                'version' => 1,
                'status' => $isGraded ? 'graded' : 'submitted',
                'github_url' => 'https://github.com/athar-trainee-'.($i + 1).'/ai101-final',
            ]);

            if (! $isGraded) {
                continue;
            }

            $this->recordGrade(
                entityType: 'final_project',
                entityId: (string) $submission->getKey(),
                participant: $participant,
                trainer: $this->trainerFor($trainers, $i),
                score: $this->scoreFor($i, 0, $maxScore, isProject: true),
                maxScore: $maxScore,
                feedback: $feedbackPool[$i % count($feedbackPool)],
                evaluatedAt: $this->clampToNow($submittedAt->addDays(2), $now),
            );
        }
    }

    private function recordGrade(
        string $entityType,
        string $entityId,
        User $participant,
        User $trainer,
        float $score,
        float $maxScore,
        string $feedback,
        CarbonImmutable $evaluatedAt,
    ): void {
        Evaluation::factory()->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $participant->getKey(),
            'score' => $score,
            'max_score' => $maxScore,
            'feedback' => $feedback,
            'evaluated_by' => $trainer->getKey(),
            'evaluated_at' => $evaluatedAt,
            'revision_reason' => null,
        ]);
    }

    /**
     * A deterministic mark that always stays inside [0, max] — the database
     * CHECK would reject anything else (PRD §7.7, BR-12).
     */
    private function scoreFor(int $index, int $ordinal, float $maxScore, bool $isProject): float
    {
        // The same fixed group that attends badly also scores below the pass mark.
        if ($index % 19 === 0) {
            $ratio = 0.30 + ((($index + $ordinal) % 10) / 100);
        } elseif ($isProject) {
            $ratio = 0.72 + ((($index * 5) % 25) / 100);
        } else {
            $ratio = 0.66 + (((($index * 7) + ($ordinal * 3)) % 30) / 100);
        }

        $score = round($maxScore * $ratio, 2);

        return max(0.0, min($score, $maxScore));
    }

    /**
     * @param  Collection<int, User>  $trainers
     */
    private function trainerFor(Collection $trainers, int $offset): User
    {
        /** @var User $trainer */
        $trainer = $trainers->get($offset % max($trainers->count(), 1));

        return $trainer;
    }

    /**
     * A stored UTC timestamp as an instant, whether the model casts the column
     * or hands back the raw string.
     */
    private function toInstant(mixed $value): CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC');
        }

        return CarbonImmutable::parse((string) $value, 'UTC');
    }

    /**
     * Nothing may be recorded as having happened in the future (BR-07).
     */
    private function clampToNow(CarbonImmutable $moment, CarbonImmutable $now): CarbonImmutable
    {
        return $moment->greaterThan($now) ? $now : $moment;
    }

    /**
     * @return Collection<int, User>
     */
    private function activeParticipants(Cohort $cohort): Collection
    {
        $userIds = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', 'participant')
            ->where('status', 'active')
            ->pluck('user_id');

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('email')
            ->get();

        return $users->values();
    }
}
