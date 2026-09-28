<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Services\FinalProject\GuideContent;
use Illuminate\Database\Seeder;

/**
 * The first content of the final project's guide (D-127) — the page the owner
 * uploaded, cleaned for the platform's rules, and its English draft.
 *
 * Run by name, on production too:
 *
 *     php artisan db:seed --force --class=Database\\Seeders\\FinalProjectGuideSeeder
 *
 * SAFE TO RUN ON PRODUCTION, BY CONSTRUCTION
 *   - No factories (Faker is gone on a --no-dev install).
 *   - It only FILLS: a language that already has any saved version is left
 *     alone, so the general supervisor's edits are never overwritten, and a
 *     second run changes nothing.
 *   - It never makes anything available or published. The general supervisor
 *     reviews the page and makes it available; the primary coordinator
 *     publishes it. Nobody is notified by this seeder.
 *
 * @see D-127 · BR-31
 */
final class FinalProjectGuideSeeder extends Seeder
{
    /** @var array<string, string> locale => file under database/seeders/data */
    public const PAGES = [
        'ar' => 'final-project-guide.ar.html',
        'en' => 'final-project-guide.en.html',
    ];

    public function run(): void
    {
        $content = app(GuideContent::class);
        $filled = 0;

        foreach (FinalProject::query()->orderBy('created_at')->get() as $project) {
            foreach (self::PAGES as $locale => $file) {
                $guide = FinalProjectGuide::query()
                    ->where('final_project_id', $project->getKey())
                    ->where('locale', $locale)
                    ->first();

                if ($guide instanceof FinalProjectGuide
                    && FinalProjectGuideVersion::query()->where('final_project_guide_id', $guide->getKey())->exists()) {
                    continue;
                }

                $content->save($project, $locale, self::page($file), FinalProjectGuideVersion::SOURCE_SEED, null);
                $filled++;
            }
        }

        $this->command->info("Final project guide: {$filled} page(s) filled. Review them, then make them available from the admin panel.");
    }

    public static function page(string $file): string
    {
        return (string) file_get_contents(database_path('seeders/data/'.$file));
    }
}
