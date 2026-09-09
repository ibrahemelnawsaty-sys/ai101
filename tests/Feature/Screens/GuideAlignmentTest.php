<?php

declare(strict_types=1);

/**
 * The landing page states the facts the centre publishes in its own guide.
 *
 * WHY THIS SUITE EXISTS
 * The centre publishes an official programme guide, and the platform disagreed
 * with it about almost everything a buyer cares about: it told search engines
 * the programme was free when it costs 1,499 SAR, it never mentioned the sixty
 * accredited hours the guide leads with, it never said the programme is remote,
 * and its FAQ gave the wrong days AND the wrong times for the live sessions.
 *
 * None of that is a rendering bug. Every one of them is the platform telling a
 * visitor something the centre did not say — which is the only kind of defect on
 * a marketing page that costs money rather than credibility alone.
 *
 * @see BR-31, BR-36 · PRD §9.1.1, §9.1.3 · CONSTITUTION.md Article 7
 */

use App\Enums\CohortStatus;
use App\Models\Program;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-08-20 12:00:00'));

    $this->cohort = makeCohort([
        'status' => CohortStatus::Open->value,
        'capacity' => 50,
        'registration_closes_at' => riyadhAt('2026-09-04 23:59:00'),
    ]);

    $this->program = Program::query()->findOrFail($this->cohort->program_id);
    $this->program->forceFill(['hours' => 60])->save();

    makeWeek($this->cohort, 1, [
        'start_date' => riyadhAt('2026-09-07 00:00:00'),
        'end_date' => riyadhAt('2026-09-09 00:00:00'),
    ]);
});

it('PRD §9.1.1: الساعات التدريبية تظهر في شارات البنر وفي شريط الثقة', function (): void {
    $body = $this->get(route('home'))->assertOk()->getContent();

    // The guide's headline figure, on the two bands PRD §9.1.1 names.
    expect($body)->toContain(trans_choice('landing.facts.chip_hours', 60, ['count' => 60]))
        ->and($body)->toContain(__('landing.facts.trust_hours'))
        ->and($body)->toContain('data-count="60"');
});

it('PRD §9.1.1: شارة «عن بُعد» تظهر، وتنضبط من الإعداد لا من القالب', function (): void {
    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain(__('landing.facts.chip_remote'));

    // A centre that moves into a room turns it off; nothing is edited out.
    config()->set('athar.program.is_remote', false);

    expect($this->get(route('home'))->assertOk()->getContent())
        ->not->toContain(__('landing.facts.chip_remote'));
});

it('BR-36: عدد الساعات يأتي من البرنامج لا من ثابت', function (): void {
    $this->program->forceFill(['hours' => 30])->save();

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('data-count="30"')
        ->and($body)->not->toContain('data-count="60"');
});

it('المادة 17: برنامج بلا ساعات مسجّلة لا يعرض شارة فارغة', function (): void {
    $this->program->forceFill(['hours' => null])->save();

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->not->toContain(__('landing.facts.trust_hours'));
});

it('المادة 7: جدول الجلسات المبذور يطابق أيام الدليل وأوقاته', function (): void {
    // The seeded FAQ used to say Sunday/Tuesday/Thursday, 7:00–9:30pm. The guide
    // says Saturday/Monday/Wednesday, 5:00–7:00pm. Both halves were wrong, and
    // they were wrong together, which is why neither looked wrong.
    expect(Database\Seeders\SeedContent::SESSION_START_TIME)->toBe('17:00:00')
        ->and(Database\Seeders\SeedContent::SESSION_END_TIME)->toBe('19:00:00');

    // Saturday-anchored weeks turn offsets 0/2/4 into Sat/Mon/Wed.
    $start = Database\Seeders\SeedContent::cohortStart();

    expect($start->dayOfWeek)->toBe(Carbon\CarbonInterface::SATURDAY);

    foreach ([0 => 'Saturday', 2 => 'Monday', 4 => 'Wednesday'] as $offset => $name) {
        expect($start->addDays($offset)->format('l'))->toBe($name);
    }
});
