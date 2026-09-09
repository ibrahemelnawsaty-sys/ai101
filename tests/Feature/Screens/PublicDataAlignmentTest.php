<?php

declare(strict_types=1);

/**
 * A programme reads the same on its own page as it does in the directory.
 *
 * WHY THIS SUITE EXISTS
 * Both public surfaces describe the same row of `programs`, and they drifted:
 * the directory read `banner_url` and rendered the picture, the landing page
 * never read the column at all. The same programme therefore appeared with an
 * illustration in one place and without one in the other — one column of data
 * and two answers, which is the shape Article 6 exists to prevent. PRD §9.1.1
 * asks the landing page's "about" section for that illustration by name.
 *
 * These tests assert the two surfaces against ONE seeded programme, so a future
 * change that feeds one of them from somewhere else fails here.
 *
 * @see BR-31, BR-36 · PRD §9.1.1 · CONSTITUTION.md Article 6
 */

use App\Models\Program;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort(['capacity' => 30]);

    $this->program = Program::query()->findOrFail($this->cohort->program_id);

    $this->program->forceFill([
        'name_ar' => 'PROGRAM-NAME-AR',
        'description' => 'PROGRAM-DESCRIPTION-SENTENCE',
        'banner_url' => 'https://example.test/banner.webp',
        'objectives' => ['OBJECTIVE-ONE', 'OBJECTIVE-TWO'],
        'target_audience' => ['AUDIENCE-ONE'],
    ])->save();
});

it('المادة 6: صورة البرنامج نفسها تظهر في الدليل وفي الصفحة الرئيسية', function (): void {
    $directory = $this->get(route('programs'))->assertOk()->getContent();
    $landing = $this->get(route('home'))->assertOk()->getContent();

    // The directory already showed it; the landing page did not read the column.
    expect($directory)->toContain('https://example.test/banner.webp')
        ->and($landing)->toContain('https://example.test/banner.webp');
});

it('المادة 6: الاسم والوصف والأهداف والفئات متطابقة بين السطحين', function (): void {
    $directory = $this->get(route('programs'))->assertOk()->getContent();
    $landing = $this->get(route('home'))->assertOk()->getContent();

    foreach (['PROGRAM-NAME-AR', 'PROGRAM-DESCRIPTION-SENTENCE', 'OBJECTIVE-ONE', 'AUDIENCE-ONE'] as $fact) {
        expect($directory)->toContain($fact)
            ->and($landing)->toContain($fact);
    }
});

it('المادة 17: برنامج بلا صورة لا يترك إطارًا فارغًا في الصفحة الرئيسية', function (): void {
    $this->program->forceFill(['banner_url' => null])->save();

    $landing = $this->get(route('home'))->assertOk()->getContent();

    expect($landing)->not->toContain('about__banner');
});
