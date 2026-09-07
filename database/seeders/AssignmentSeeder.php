<?php

declare(strict_types=1);

/**
 * The four performance assignments and the closing project.
 *
 * The four `max_score` values sum to exactly 50 and the project carries the
 * other 50, so the grand total is 100 (BR-11). The sum is asserted here rather
 * than trusted, because a seed that silently breaks BR-11 would make every
 * grading test meaningless.
 *
 * Each assignment falls due at the end of its own week, so by the time the
 * cohort reaches its final week all four are closed and gradable.
 *
 * @see PRD §7.5, §7.6 · BR-11, BR-12 · PROJECT-CONTRACT §4, §7
 */

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\User;
use App\Models\Week;
use Illuminate\Database\Seeder;
use RuntimeException;

final class AssignmentSeeder extends Seeder
{
    /** BR-11: the assignments together are worth 50 of the 100 marks. */
    private const ASSIGNMENTS_TOTAL = 50;

    /** BR-11: the closing project is worth the other 50. */
    private const PROJECT_TOTAL = 50;

    /** Day offset on which the closing project is opened to participants. */
    private const PROJECT_UNLOCK_DAY = 21;

    /** Day offset of the closing project deadline — always still ahead. */
    private const PROJECT_DUE_DAY = 34;

    public function run(): void
    {
        $cohort = Cohort::query()->firstOrFail();

        $trainer = User::query()
            ->where('role', 'trainer')
            ->orderBy('email')
            ->firstOrFail();

        $this->createAssignments($cohort, $trainer);
        $this->createFinalProject($cohort, $trainer);
    }

    private function createAssignments(Cohort $cohort, User $trainer): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = SeedContent::section('assignments');

        $total = array_sum(array_map(static fn (array $row): int => (int) $row['max_score'], $rows));

        if ($total !== self::ASSIGNMENTS_TOTAL) {
            throw new RuntimeException(
                'BR-11 violated by the seed data: the assignments total '
                .$total.' marks instead of '.self::ASSIGNMENTS_TOTAL.'.'
            );
        }

        foreach ($rows as $row) {
            $weekIndex = (int) $row['week'];

            $week = Week::query()
                ->where('cohort_id', $cohort->getKey())
                ->where('index', $weekIndex)
                ->firstOrFail();

            Assignment::factory()->create([
                'cohort_id' => $cohort->getKey(),
                'week_id' => $week->getKey(),
                'title' => $row['title'],
                'description' => $row['description'],
                'is_mandatory' => true,
                'max_score' => (int) $row['max_score'],
                'due_at' => SeedContent::instant((($weekIndex - 1) * 7) + 6, '23:59:00'),
                'allow_late' => (bool) $row['allow_late'],
                'allow_github_link' => true,
                'max_file_size_mb' => 10,
                'max_files' => 3,
                'attachments' => [],
                'status' => 'published',
                'created_by' => $trainer->getKey(),
            ]);
        }
    }

    private function createFinalProject(Cohort $cohort, User $trainer): void
    {
        /** @var array<string, mixed> $row */
        $row = SeedContent::section('final_project');

        FinalProject::factory()->create([
            'cohort_id' => $cohort->getKey(),
            'title' => $row['title'],
            'brief' => $row['brief'],
            'requirements' => $row['requirements'],
            'is_unlocked' => true,
            'unlocked_at' => SeedContent::instant(self::PROJECT_UNLOCK_DAY, '19:00:00'),
            'unlocked_by' => $trainer->getKey(),
            'due_at' => SeedContent::instant(self::PROJECT_DUE_DAY, '23:59:00'),
            'max_score' => self::PROJECT_TOTAL,
            'attachments' => [],
        ]);
    }
}
