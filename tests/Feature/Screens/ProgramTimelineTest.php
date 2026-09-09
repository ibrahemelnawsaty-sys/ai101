<?php

declare(strict_types=1);

/**
 * The timeline shows the programme's arc, not a second copy of the week cards.
 *
 * WHY THIS SUITE EXISTS
 * PRD §9.1.1 asks this section for the stages of the programme WITH THEIR DATES:
 * registration, the introductory meeting, the four weeks, the project, the
 * ceremony. It was handed the week list verbatim instead — so it repeated the
 * four titles from the section directly above it, and printed them with no date
 * at all, because the template reads `when` and the week list carries `dates`.
 *
 * Four headings and four empty spans, immediately under four cards that said the
 * same four things. The section occupied a screen and added nothing.
 *
 * A stage the centre has not scheduled is DROPPED rather than rendered with an
 * empty date: an undated milestone is precisely the defect this replaces.
 *
 * @see BR-31, BR-36 · PRD §9.1.1 · CONSTITUTION.md Article 17
 */

use App\Enums\SessionType;
use App\Models\FinalProject;
use App\Models\Session;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort([
        'capacity' => 30,
        'registration_closes_at' => riyadhAt('2026-10-01 20:00:00'),
    ]);
});

/** The milestone list only, so a date printed elsewhere cannot satisfy these. */
function timelineRow(string $body): string
{
    return preg_match('/<ol class="tl__row">(.*?)<\/ol>/s', $body, $m) === 1 ? $m[1] : '';
}

it('PRD §9.1.1: الخط الزمني يعرض مراحل البرنامج لا تكرار الأسابيع', function (): void {
    makeWeek($this->cohort, 1, [
        'title' => 'WEEK-ONE',
        'start_date' => riyadhAt('2026-10-04 00:00:00'),
        'end_date' => riyadhAt('2026-10-10 00:00:00'),
    ]);
    makeWeek($this->cohort, 2, [
        'title' => 'WEEK-TWO',
        'start_date' => riyadhAt('2026-10-11 00:00:00'),
        'end_date' => riyadhAt('2026-10-17 00:00:00'),
    ]);

    Session::factory()->create([
        'cohort_id' => $this->cohort->id,
        'type' => SessionType::Intro->value,
        'date' => riyadhAt('2026-10-02 00:00:00'),
    ]);

    Session::factory()->create([
        'cohort_id' => $this->cohort->id,
        'type' => SessionType::Closing->value,
        'date' => riyadhAt('2026-10-25 00:00:00'),
    ]);

    FinalProject::factory()->create([
        'cohort_id' => $this->cohort->id,
        'due_at' => riyadhAt('2026-10-20 23:59:00'),
    ]);

    $row = timelineRow($this->get(route('home'))->assertOk()->getContent());

    // Five named stages, each one PRD §9.1.1 asks for by name.
    expect($row)->toContain(__('landing.headings.timeline.registration_closes'))
        ->and($row)->toContain(__('landing.headings.timeline.intro_session'))
        ->and($row)->toContain(__('landing.headings.timeline.training_weeks'))
        ->and($row)->toContain(__('landing.headings.timeline.final_project'))
        ->and($row)->toContain(__('landing.headings.timeline.closing_session'))
        // And NOT the week titles: repeating them is the defect being replaced.
        ->and($row)->not->toContain('WEEK-ONE')
        ->and($row)->not->toContain('WEEK-TWO');
});

it('PRD §9.1.1: كل مرحلة معروضة تحمل تاريخًا، ولا محطة فارغة', function (): void {
    makeWeek($this->cohort, 1, [
        'start_date' => riyadhAt('2026-10-04 00:00:00'),
        'end_date' => riyadhAt('2026-10-10 00:00:00'),
    ]);

    $row = timelineRow($this->get(route('home'))->assertOk()->getContent());

    expect($row)->not->toBe('')
        // The exact shape of the old bug: a milestone with an empty date span.
        ->and($row)->not->toMatch('/<span>\s*<\/span>/');
});

it('المادة 17: مرحلة لم يجدولها المركز تُحذف ولا تُعرض بتاريخ فارغ', function (): void {
    // No intro session, no closing session, no final project — only the two
    // stages the cohort actually has.
    makeWeek($this->cohort, 1, [
        'start_date' => riyadhAt('2026-10-04 00:00:00'),
        'end_date' => riyadhAt('2026-10-10 00:00:00'),
    ]);

    $row = timelineRow($this->get(route('home'))->assertOk()->getContent());

    expect($row)->toContain(__('landing.headings.timeline.registration_closes'))
        ->and($row)->toContain(__('landing.headings.timeline.training_weeks'))
        ->and($row)->not->toContain(__('landing.headings.timeline.intro_session'))
        ->and($row)->not->toContain(__('landing.headings.timeline.closing_session'))
        ->and($row)->not->toContain(__('landing.headings.timeline.final_project'));
});

it('المادة 15: تواريخ الخط الزمني بأرقام لاتينية', function (): void {
    makeWeek($this->cohort, 1, [
        'start_date' => riyadhAt('2026-10-04 00:00:00'),
        'end_date' => riyadhAt('2026-10-10 00:00:00'),
    ]);

    $row = timelineRow($this->get(route('home'))->assertOk()->getContent());

    expect($row)->toContain('2026')
        // Arabic-Indic digits must never reach the page.
        ->and($row)->not->toMatch('/[\x{0660}-\x{0669}]/u');
});
