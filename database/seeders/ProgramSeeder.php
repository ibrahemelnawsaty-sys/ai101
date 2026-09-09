<?php

declare(strict_types=1);

/**
 * The programme, its single cohort, the four weeks, the ten journey steps and
 * the landing page settings.
 *
 * The cohort carries the two certificate thresholds fixed by the contract:
 * pass_score 60 and min_attendance_rate 75 (BR-26).
 *
 * @see PRD §7.2, §7.3, §7.6, §9.7 · BR-20, BR-26, BR-31, BR-36 · PROJECT-CONTRACT §4, §9
 */

namespace Database\Seeders;

use App\Models\Cohort;
use App\Models\JourneyStep;
use App\Models\LandingSetting;
use App\Models\Program;
use App\Models\Week;
use Illuminate\Database\Seeder;

final class ProgramSeeder extends Seeder
{
    public function run(): void
    {
        $program = $this->createProgram();
        $cohort = $this->createCohort($program);
        $weeks = $this->createWeeks($cohort);

        $this->createJourneySteps($cohort, $weeks);
        $this->createLandingSettings($cohort);
    }

    private function createProgram(): Program
    {
        /** @var array<string, mixed> $content */
        $content = SeedContent::section('program');

        return Program::factory()->create([
            'name_ar' => $content['name_ar'],
            'name_en' => $content['name_en'],
            'slug' => $content['slug'],
            'description' => $content['description'],
            'objectives' => $content['objectives'],
            'target_audience' => $content['target_audience'],
            'certificates' => $content['certificates'],
            // The column existed from the admin-screens migration and was never
            // written, so the figure the guide leads with — sixty accredited
            // hours — was absent from the hero badges and the trust bar alike.
            'hours' => $content['hours'],
            'status' => 'published',
        ]);
    }

    private function createCohort(Program $program): Cohort
    {
        /** @var array<string, mixed> $content */
        $content = SeedContent::section('cohort');

        return Cohort::factory()->create([
            'program_id' => $program->getKey(),
            'name' => $content['name'],
            'start_date' => SeedContent::dateOn(0),
            'end_date' => SeedContent::dateOn(SeedContent::CLOSING_DAY),
            'capacity' => 60,
            'registration_closes_at' => SeedContent::instant(-1, '23:59:00'),
            'seats_taken' => 0,
            'status' => 'running',
            'pass_score' => 60,
            'min_attendance_rate' => 75,
        ]);
    }

    /**
     * @return array<int, Week> keyed by the 1-based week index
     */
    private function createWeeks(Cohort $cohort): array
    {
        $weeks = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = SeedContent::section('weeks');

        foreach ($rows as $row) {
            $index = (int) $row['index'];
            $firstDay = ($index - 1) * 7;

            $weeks[$index] = Week::factory()->create([
                'cohort_id' => $cohort->getKey(),
                'index' => $index,
                'title' => $row['title'],
                'objectives' => $row['objectives'],
                'start_date' => SeedContent::dateOn($firstDay),
                'end_date' => SeedContent::dateOn($firstDay + 6),
            ]);
        }

        return $weeks;
    }

    /**
     * The ten steps of PROJECT-CONTRACT §9. Week steps point at their week so
     * the JourneyEvaluator can resolve them without guessing from the title.
     *
     * @param  array<int, Week>  $weeks
     */
    private function createJourneySteps(Cohort $cohort, array $weeks): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = SeedContent::section('journey_steps');

        $weekStepIndexes = [3 => 1, 4 => 2, 5 => 3, 6 => 4];

        foreach ($rows as $row) {
            $index = (int) $row['index'];
            $relatedWeek = $weekStepIndexes[$index] ?? null;

            JourneyStep::factory()->create([
                'cohort_id' => $cohort->getKey(),
                'index' => $index,
                'title' => $row['title'],
                'description' => $row['description'],
                'type' => $row['type'],
                'unlock_rule' => $row['unlock_rule'],
                'related_entity_type' => $relatedWeek === null ? null : 'week',
                'related_entity_id' => $relatedWeek === null ? null : $weeks[$relatedWeek]->getKey(),
                'icon' => $row['icon'],
            ]);
        }
    }

    private function createLandingSettings(Cohort $cohort): void
    {
        /** @var array<string, mixed> $content */
        $content = SeedContent::section('landing');

        LandingSetting::factory()->create([
            'cohort_id' => $cohort->getKey(),
            'seats_remaining_override' => null,
            'countdown_enabled' => true,
            'hero_text' => $content['hero_text'],
            'faq' => $content['faq'],
            'is_registration_open' => true,
        ]);
    }
}
