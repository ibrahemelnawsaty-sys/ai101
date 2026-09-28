<?php

declare(strict_types=1);

/**
 * D-127 — the guide's first content: the owner's page, cleaned for the
 * platform, and its English draft. The seeder only fills, never overwrites the
 * supervisor's edits, and never makes anything available or published.
 *
 * @see D-127 · BR-31 · CONSTITUTION Art. 14, Art. 24
 */

use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Models\Notification;
use App\Rules\GuidePageColours;
use App\Services\FinalProject\GuideContent;
use Database\Seeders\FinalProjectGuideSeeder;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-28 12:00:00'));

    $this->cohort = makeCohort();
    makeCoordinator($this->cohort);
    $this->project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
});

it('D-127: البذرة تملأ الدليل بلغتيه غير متاح وغير منشور، ولا تُشعر أحدًا، وتشغيلها ثانيةً لا يغيّر شيئًا', function (): void {
    $this->seed(FinalProjectGuideSeeder::class);
    $this->seed(FinalProjectGuideSeeder::class);

    $guides = FinalProjectGuide::query()->where('final_project_id', $this->project->id)->get()->keyBy('locale');

    expect($guides->keys()->sort()->values()->all())->toBe(['ar', 'en'])
        ->and($guides->every(fn (FinalProjectGuide $guide): bool => ! $guide->is_available && ! $guide->is_published))->toBeTrue()
        ->and(FinalProjectGuideVersion::query()->count())->toBe(2)
        ->and(FinalProjectGuideVersion::query()->pluck('source')->unique()->all())->toBe([FinalProjectGuideVersion::SOURCE_SEED])
        ->and(Notification::query()->count())->toBe(0);
});

it('D-127: البذرة لا تكتب فوق دليل عدّله المشرف العام', function (): void {
    app(GuideContent::class)->save($this->project, 'ar', '<html><body>EDITED</body></html>', FinalProjectGuideVersion::SOURCE_EDITOR, makeAdmin());

    $this->seed(FinalProjectGuideSeeder::class);

    $arabic = FinalProjectGuide::query()->where('locale', 'ar')->with('currentVersion')->sole();

    expect($arabic->currentVersion->html)->toContain('EDITED')
        ->and($arabic->currentVersion->version)->toBe(1);
});

it('D-127, المادة 14: صفحتا البذرة بلا أصفر ولا ذهبي، وبلا سكربت ولا روابط إلى جهاز شخصي ولا خطوط خارجية', function (): void {
    foreach (FinalProjectGuideSeeder::PAGES as $locale => $file) {
        $page = FinalProjectGuideSeeder::page($file);

        expect(GuidePageColours::offenders($page))->toBe([])
            ->and($page)->not->toContain('<script')
            ->and($page)->not->toContain('file:///')
            ->and($page)->not->toContain('fonts.googleapis.com')
            ->and($page)->not->toContain('saved from url')
            ->and(strlen($page))->toBeLessThan(GuideContent::MAX_BYTES)
            ->and($page)->toContain('<html lang="'.$locale.'"');
    }
});
