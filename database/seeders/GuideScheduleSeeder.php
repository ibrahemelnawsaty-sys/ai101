<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\Program;
use App\Models\Session;
use App\Models\Week;
use App\Services\Time\Clock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The cohort exactly as the centre's official programme guide publishes it.
 *
 * WHY THIS SEEDER EXISTS AND WHY IT IS SEPARATE
 * The landing page renders what the database holds. Production held demo data:
 * weeks dated in the past, no sessions at all, no final project — so the public
 * timeline showed two milestones out of five, every week card read "بلا جلسات
 * مباشرة", and the certificate simulator divided by zero. None of that is a code
 * defect; all of it is absent data.
 *
 * `DatabaseSeeder` cannot be used to fix it: it pulls the demo seeders, which
 * invent sixty participants and relative dates. This seeder is invoked by name
 * and touches nothing else.
 *
 * SAFE TO RUN ON PRODUCTION, BY CONSTRUCTION
 *   - No model factories. Factories need Faker, which `--no-dev` deletes — which
 *     is why every existing seeder fails on a production install.
 *   - Idempotent. Every write is an updateOrCreate keyed on something stable, so
 *     running it twice changes nothing and creates no duplicate.
 *   - It never deletes. No attendance, evaluation, submission or enrolment row is
 *     touched, read or removed.
 *   - Fixed dates from the guide, never computed from "today" (BR-07).
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * It seeds no assignments. The guide publishes suggested project ideas, not four
 * graded tasks with weights, and BR-11 fixes the assignment total at fifty —
 * inventing titles and splitting marks would be content this seeder has no source
 * for. Assignments stay an admin-panel job.
 *
 * @see BR-07, BR-31, BR-36 · PRD §9.1.1, §9.10 · D-61, D-62
 */
final class GuideScheduleSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, mixed> $guide */
        $guide = $this->guide();

        $program = Program::query()->where('status', 'published')->orderBy('created_at')->first();

        if ($program === null) {
            $this->command->error('No published programme found. Run ProgramSeeder first, or publish one from the admin panel.');

            return;
        }

        DB::transaction(function () use ($guide, $program): void {
            $cohort = $this->cohort($program, $guide);
            $weeks = $this->weeks($cohort, $guide);

            $this->sessions($cohort, $weeks, $guide);
            $this->finalProject($cohort, $guide);
        });

        $this->command->info('Guide schedule seeded. Clear the caches, then check the public timeline.');
    }

    /** @return array<string, mixed> */
    private function guide(): array
    {
        $path = database_path('seeders/data/guide-schedule.json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The cohort, matched on its programme and name so a second run updates the
     * same row rather than adding a rival cohort the landing page might pick.
     *
     * `status` and `capacity` are NOT written: the centre owns both, and
     * overwriting a status it set from the panel would be this seeder deciding
     * whether registration is open.
     *
     * @param  array<string, mixed>  $guide
     */
    private function cohort(Program $program, array $guide): Cohort
    {
        /** @var array<string, string> $row */
        $row = $guide['cohort'];

        /** @var Cohort $cohort */
        $cohort = Cohort::query()->updateOrCreate(
            [
                'program_id' => $program->getKey(),
                'name' => $row['name'],
            ],
            [
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'registration_closes_at' => Clock::fromRiyadh($row['registration_closes_at']),
            ],
        );

        return $cohort;
    }

    /**
     * The four weeks. `weeks` is unique on (cohort_id, index), so the index is
     * the natural key and a re-run simply corrects the dates.
     *
     * @param  array<string, mixed>  $guide
     * @return array<int, Week> keyed by the week's index
     */
    private function weeks(Cohort $cohort, array $guide): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $guide['weeks'];

        $weeks = [];

        foreach ($rows as $row) {
            $index = (int) $row['index'];

            /** @var Week $week */
            $week = Week::query()->updateOrCreate(
                [
                    'cohort_id' => $cohort->getKey(),
                    'index' => $index,
                ],
                [
                    'title' => $this->weekTitle($index, (string) $row['theme']),
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'objectives' => $this->weekObjectives($guide, $index),
                ],
            );

            $weeks[$index] = $week;
        }

        return $weeks;
    }

    /**
     * One session per day, spanning the whole window the centre set.
     *
     * The guide publishes each training day as two blocks with a break between
     * them. The centre chose to record one session per day instead (D-61), so a
     * participant checks in once rather than twice. Both block titles survive in
     * `topic`, so the page still tells the visitor what each day covers.
     *
     * Matched on (cohort_id, date): one session per calendar day is exactly the
     * model being seeded, so the date is the natural key.
     *
     * @param  array<string, mixed>  $guide
     * @param  array<int, Week>  $weeks
     */
    private function sessions(Cohort $cohort, array $weeks, array $guide): void
    {
        /** @var array<string, string> $window */
        $window = $guide['session_window'];

        /** @var list<array<string, mixed>> $days */
        $days = $guide['days'];

        foreach ($days as $day) {
            $weekIndex = $day['week'];

            // NOT updateOrCreate on ['date' => ...]. `date` is cast to a Carbon
            // date, so Eloquent widens the string to an instant before building
            // the WHERE, the comparison misses the stored value, and a re-run
            // inserts a second row for the same day — thirteen sessions became
            // thirty-nine on the third run. `whereDate` compares calendar days,
            // which is what "one session per day" actually means.
            $existing = Session::query()
                ->where('cohort_id', $cohort->getKey())
                ->whereDate('date', $day['date'])
                ->first();

            $attributes = [
                'cohort_id' => $cohort->getKey(),
                'date' => $day['date'],
                'week_id' => is_int($weekIndex) ? $weeks[$weekIndex]->getKey() : null,
                'title' => $day['title'],
                'topic' => $day['topic'],
                'type' => $day['type'],
                'start_time' => $window['start_time'],
                'end_time' => $window['end_time'],
                // `status` is deliberately absent, and it is not in Session's
                // $fillable either — writing it here would have been silently
                // dropped. The column defaults to `scheduled` for a new row,
                // and leaving an existing row alone preserves a cancellation
                // a trainer recorded. The meeting link is likewise the
                // trainer's to paste (D-18), never a seeder's to invent.
            ];

            if ($existing === null) {
                Session::query()->create($attributes);

                continue;
            }

            $existing->fill($attributes)->save();
        }
    }

    /**
     * The final project, one per cohort.
     *
     * `is_unlocked` is not written. BR-15 makes unlocking a trainer's act, and a
     * seeder that unlocked it would hand every participant a deliverable the
     * trainer had not opened.
     *
     * @param  array<string, mixed>  $guide
     */
    private function finalProject(Cohort $cohort, array $guide): void
    {
        /** @var array<string, mixed> $row */
        $row = $guide['final_project'];

        FinalProject::query()->updateOrCreate(
            ['cohort_id' => $cohort->getKey()],
            [
                'title' => $row['title'],
                'brief' => $row['brief'],
                'requirements' => $row['requirements'],
                'due_at' => Clock::fromRiyadh((string) $row['due_at']),
            ],
        );
    }

    /** The week's public title: its ordinal and the theme the guide gives it. */
    private function weekTitle(int $index, string $theme): string
    {
        $ordinals = [1 => 'الأول', 2 => 'الثاني', 3 => 'الثالث', 4 => 'الرابع'];

        return 'الأسبوع '.($ordinals[$index] ?? (string) $index).' — '.$theme;
    }

    /**
     * What the week covers, taken from the titles of that week's own sessions —
     * so the week card and the day cards can never disagree.
     *
     * @param  array<string, mixed>  $guide
     * @return list<string>
     */
    private function weekObjectives(array $guide, int $index): array
    {
        /** @var list<array<string, mixed>> $days */
        $days = $guide['days'];

        $objectives = [];

        foreach ($days as $day) {
            if ($day['week'] !== $index) {
                continue;
            }

            foreach (explode(' · ', (string) $day['topic']) as $part) {
                $objectives[] = trim($part);
            }
        }

        return array_values(array_unique($objectives));
    }
}
