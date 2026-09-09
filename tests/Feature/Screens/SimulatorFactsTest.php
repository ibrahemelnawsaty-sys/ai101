<?php

declare(strict_types=1);

/**
 * Every number the certificate simulator hands the browser is the cohort's own.
 *
 * WHY THIS SUITE EXISTS
 * The simulator asked "كم مهمة أدائية ستسلّم؟" and offered a slider that ran to
 * 50, on a four-week programme with four assignments. `cohortFacts()` filled
 * BOTH `assignments_points` and `assignments_total` from
 * `ScoreCalculator::ASSIGNMENTS_TOTAL`, which is 50 POINTS — so a mark total was
 * printed where a count belonged.
 *
 * That is not a cosmetic slip. The widget tells the visitor, in its own words,
 * that "الحساب يجري بقواعد المنصة نفسها — لا تقدير ولا تقريب", so a wrong
 * denominator is BR-11 stated wrongly in public, on the one surface that claims
 * to be exact. The points and the count are different quantities that happened
 * to be adjacent in the same array.
 *
 * These assertions read the RENDERED attributes rather than the controller, so
 * the contract they guard is the one the browser actually receives.
 *
 * @see BR-11, BR-26, BR-31, BR-36 · PRD §9.1 · CONSTITUTION.md Article 5
 */

use App\Services\Grading\ScoreCalculator;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort(['capacity' => 30, 'pass_score' => 60, 'min_attendance_rate' => 75]);
});

/** The value of one `data-*` attribute on the simulator root. */
function simFact(string $body, string $attribute): ?string
{
    return preg_match('/<div class="sim[^"]*"[^>]*\sdata-'.$attribute.'="([^"]*)"/s', $body, $m) === 1
        ? $m[1]
        : null;
}

it('BR-11: عدد المهام في المحاكي هو عدد مهام الدفعة لا مجموع درجاتها', function (): void {
    // Four assignments worth fifty marks between them — the exact shape that
    // made the bug invisible, because 50 is a plausible-looking number.
    foreach (range(1, 4) as $ignored) {
        makeAssignment($this->cohort);
    }

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect(simFact($body, 'tasks'))->toBe('4')
        ->and(simFact($body, 'task-points'))->toBe((string) ScoreCalculator::ASSIGNMENTS_TOTAL);
});

it('BR-11: دفعة بلا مهام تعرض صفرًا لا خمسين', function (): void {
    // The production cohort had no assignments at all, and still offered a
    // slider running to 50. Zero is the honest answer.
    $body = $this->get(route('home'))->assertOk()->getContent();

    expect(simFact($body, 'tasks'))->toBe('0');
});

it('BR-11: الدرجات والعتبات تُقرأ من الدفعة لا من ثابت في القالب', function (): void {
    $body = $this->get(route('home'))->assertOk()->getContent();

    expect(simFact($body, 'pass-score'))->toBe('60')
        ->and(simFact($body, 'min-attendance'))->toBe('75')
        ->and(simFact($body, 'project-points'))->toBe((string) ScoreCalculator::PROJECT_TOTAL);
});
