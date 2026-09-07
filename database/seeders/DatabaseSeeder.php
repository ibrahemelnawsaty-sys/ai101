<?php

declare(strict_types=1);

/**
 * Builds one complete, hand-testable cohort (Constitution art. 29).
 *
 * Order matters: every seeder below depends on the rows the ones above it
 * created, so this list is also the dependency graph.
 *
 * Deliberately NOT seeded:
 *  - `user_journey_states` — BR-21 makes JourneyEvaluator the only writer of
 *    journey completion; seeding it by hand would create a second source of
 *    truth for the same fact (Constitution art. 6).
 *  - `enrollments.final_score` / `attendance_rate` — caches owned by
 *    ScoreCalculator and CertificateEligibility, for the same reason.
 *  - `audit_logs` — written by the audit service at the moment of an action,
 *    never fabricated (Constitution art. 8).
 *
 * @see PRD §7 · Constitution art. 6, art. 8, art. 29 · BR-21, BR-31
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProgramSeeder::class,
            UserSeeder::class,
            ScheduleSeeder::class,
            AssignmentSeeder::class,
            AttendanceSeeder::class,
            SubmissionSeeder::class,
            EngagementSeeder::class,
            CertificateSeeder::class,
        ]);
    }
}
