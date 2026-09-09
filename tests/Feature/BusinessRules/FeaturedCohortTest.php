<?php

declare(strict_types=1);

/**
 * The admin panel edits the cohort the public page is showing — always.
 *
 * WHY THIS SUITE EXISTS
 * Two places decided which cohort is "the current one" and they did not agree.
 * The public page preferred open, then upcoming, then RUNNING; the landing
 * editor looked only at open and upcoming. On a cohort that had started — the
 * state the seeded production database was in — the page happily rendered it
 * while every save in the admin panel came back with `admin.landing.no_cohort`.
 *
 * BR-31 says the centre edits this content from the admin panel. Two answers to
 * "which cohort" does not weaken that rule, it voids it: either nothing can be
 * edited, or an administrator edits one cohort and a visitor reads another,
 * which is the worse of the two failures because it looks like it worked.
 *
 * The rule now lives on the model, and these tests hold both callers to it.
 *
 * @see BR-31 · PRD §9.1, §9.18 · CONSTITUTION.md Article 6
 */

use App\Enums\CohortStatus;
use App\Models\Cohort;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));
});

it('BR-31: صفحة الهبوط ولوحة الإدارة تختاران الدفعة نفسها في كل حالة', function (string $status): void {
    $cohort = makeCohort(['status' => $status]);

    $admin = makeUser('admin');

    // What the visitor sees.
    $public = $this->get(route('home'))->assertOk()->getContent();

    // What the editor offers to change.
    $editor = $this->actingAs($admin)->get(route('admin.landing.edit'))->assertOk()->getContent();

    expect(Cohort::featured()?->getKey())->toBe($cohort->getKey())
        ->and($public)->toContain('data-state="normal"')
        // The editor names the cohort it is about to write to; an editor that
        // found none prints the no-cohort error instead.
        ->and($editor)->toContain((string) $cohort->getAttribute('name'))
        ->and($editor)->not->toContain(__('admin.landing.no_cohort'));
})->with([
    'open' => CohortStatus::Open->value,
    'upcoming' => CohortStatus::Upcoming->value,
    // The state the production database was in, and the one the editor could
    // not see at all.
    'running' => CohortStatus::Running->value,
]);

it('BR-31: الدفعة المفتوحة تسبق القادمة وتسبق الجارية', function (): void {
    // Order matters as much as membership: a centre running one cohort while
    // registration is open on the next must edit the one it is selling.
    $running = makeCohort(['status' => CohortStatus::Running->value, 'start_date' => riyadhAt('2026-08-01 00:00:00')]);
    $upcoming = makeCohort(['status' => CohortStatus::Upcoming->value, 'start_date' => riyadhAt('2026-11-01 00:00:00')]);
    $open = makeCohort(['status' => CohortStatus::Open->value, 'start_date' => riyadhAt('2026-12-01 00:00:00')]);

    expect(Cohort::featured()?->getKey())->toBe($open->getKey());

    $open->forceFill(['status' => CohortStatus::Completed->value])->save();

    expect(Cohort::featured()?->getKey())->toBe($upcoming->getKey());

    $upcoming->forceFill(['status' => CohortStatus::Completed->value])->save();

    expect(Cohort::featured()?->getKey())->toBe($running->getKey());
});

it('BR-31: لا دفعة منشورة يعني لا اختيار، لا سقوط إلى المنتهية', function (): void {
    makeCohort(['status' => CohortStatus::Completed->value]);

    expect(Cohort::featured())->toBeNull();
});
