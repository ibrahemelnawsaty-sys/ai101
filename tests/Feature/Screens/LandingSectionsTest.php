<?php

declare(strict_types=1);

/**
 * The landing page renders the values it was handed, in the shape it expects.
 *
 * WHY THIS SUITE EXISTS
 * Three defects shipped together, and none of them raised anything anywhere: a
 * key mismatch, a null where a missing key was meant, and a value nobody passed.
 * All three render as SILENCE — an empty span, an icon-shaped hole, an empty
 * attribute — so the page looked finished and the suite stayed green.
 *
 *   - The timeline printed `when`; the controller supplied `dates`. Four
 *     milestones rendered with no date beside any of them.
 *   - Cards carried `'icon' => null`. `data_get` tests with array_key_exists, so
 *     the template's declared fallback never fired and `<use href="#i-">` left
 *     seven holes on the live page.
 *   - Nothing passed `pageDescription`, so four meta surfaces shipped empty.
 *
 * A missing value that renders as nothing is the hardest kind to notice, which
 * is exactly why it needs a test rather than a reviewer.
 *
 * @see BR-31, BR-36 · PRD §9.1.1, §9.1.3 · CONSTITUTION.md Article 17
 */

use App\Models\LandingSetting;
use App\Models\Program;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort(['capacity' => 30]);
});

it('PRD §9.1.1: كل محطة في الخط الزمني تحمل تاريخها', function (): void {
    makeWeek($this->cohort, 1, [
        'title' => 'WEEK-ONE-TITLE',
        'start_date' => riyadhAt('2026-10-04 00:00:00'),
        'end_date' => riyadhAt('2026-10-10 00:00:00'),
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    // The milestone list, isolated so a date printed elsewhere cannot pass it.
    expect(preg_match('/<ol class="tl__row">(.*?)<\/ol>/s', $body, $row))->toBe(1);

    expect($row[1])->toContain('WEEK-ONE-TITLE')
        // An empty <span></span> is what the mismatch produced.
        ->and($row[1])->not->toMatch('/<span>\s*<\/span>/');
});

it('المادة 16: لا أيقونة بمعرّف فارغ على الصفحة', function (): void {
    // `#i-` resolves to nothing and throws nothing: the icon is simply absent
    // and the layout keeps its space. Five goals and two seals rendered so.
    $program = Program::query()->findOrFail($this->cohort->program_id);

    $program->forceFill([
        // Stored exactly as the admin screen stores them: a bare string carries
        // no icon at all, an object may carry one.
        'objectives' => ['GOAL-WITHOUT-ICON', ['title' => 'GOAL-WITH-ICON', 'icon' => 'chart']],
    ])->save();

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->not->toContain('href="#i-"')
        // The one that named an icon keeps it; the one that did not falls back.
        ->and($body)->toContain('href="#i-chart"')
        ->and($body)->toContain('href="#i-spark"');
});

it('PRD §9.1.3: وسم الوصف ليس فارغًا ويأتي من قاعدة البيانات', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => 'HERO-SENTENCE-FROM-DATABASE',
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    // One value, four surfaces — every one of them was empty.
    expect($body)->toContain('<meta name="description" content="HERO-SENTENCE-FROM-DATABASE">')
        ->and($body)->toContain('<meta property="og:description" content="HERO-SENTENCE-FROM-DATABASE">')
        ->and($body)->toContain('<meta name="twitter:description" content="HERO-SENTENCE-FROM-DATABASE">')
        ->and($body)->toContain('"description":"HERO-SENTENCE-FROM-DATABASE"');
});

it('PRD §9.1.3: الوصف الطويل يُقصّ عند حدّ كلمة لا في منتصفها', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => str_repeat('word ', 60),
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect(preg_match('/<meta name="description" content="([^"]*)">/', $body, $meta))->toBe(1);

    expect(mb_strlen($meta[1]))->toBeLessThanOrEqual(161)
        ->and($meta[1])->toEndWith('…')
        // A cut mid-word would leave a fragment; every token here is whole.
        ->and(rtrim($meta[1], '… '))->toEndWith('word');
});
