<?php

declare(strict_types=1);

/**
 * Phase 5 · D-145 — the counter that says what is waiting for the trainer's mark is the
 * number the grading queue will actually open.
 *
 * «Waiting for your mark» counted every version that has no evaluation, including the earlier
 * copies a participant handed in again (BR-19 keeps them all). On development data it read 30
 * while the queue held 20: ten cards that could never be opened. The owner chose option A: the
 * counter follows the same definition as the queue (`newestVersionOnly`), while «arrived» and
 * «late» stay over every version — each one is a hand-in that did arrive, and arrival is an event
 * that has happened, not a list to work through.
 *
 * Nothing here records, changes or hides a mark; it decides what a counter counts.
 *
 * @see BR-19, BR-23 · PRD §9.11.3 · D-136, D-145
 */

use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-02 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60]);
    $this->trainer = makeTrainer($this->cohort);
    $this->assignment = makeAssignment($this->cohort, ['title' => 'Assignment One']);
});

/** The four counters the board shows, read from the page the trainer gets. */
function boardStats(object $test): object
{
    Auth::forgetGuards();

    return $test->actingAs($test->trainer)
        ->get(route('trainer.submissions', ['cohort' => $test->cohort->id]))
        ->assertOk()
        ->viewData('stats');
}

it('D-145: «بانتظار تقييمك» تعدّ أحدث نسخة بلا تقييم فقط، لا نسخًا سابقة أُعيد التسليم بعدها', function (): void {
    $sara = makeParticipant($this->cohort);
    $omar = makeParticipant($this->cohort);
    $lina = makeParticipant($this->cohort);
    $nadia = makeParticipant($this->cohort);

    // Sara handed in twice and nothing is marked: ONE thing to mark (her newest).
    makeSubmission($this->assignment, $sara, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
    makeSubmission($this->assignment, $sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-20 10:00:00')]);

    // Omar handed in once, late, unmarked: one.
    makeSubmission($this->assignment, $omar, ['is_late' => true]);

    // Lina's first copy was marked and she handed in again: her newest is waiting — one.
    $first = makeSubmission($this->assignment, $lina, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
    makeEvaluation('assignment', $first->id, $lina, 7);
    makeSubmission($this->assignment, $lina, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-20 10:00:00')]);

    // Nadia's first copy was never marked, but her newest was: NOTHING is waiting. The old count
    // still held her first copy, which the queue will never open.
    makeSubmission($this->assignment, $nadia, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
    $newest = makeSubmission($this->assignment, $nadia, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-20 10:00:00')]);
    makeEvaluation('assignment', $newest->id, $nadia, 9);

    expect(boardStats($this)->awaitingGrading)->toBe(3);
});

it('D-145: «وصلت» و«متأخرة» تبقيان على كل النسخ — كل نسخة تسليم وصل فعلًا', function (): void {
    $sara = makeParticipant($this->cohort);
    $omar = makeParticipant($this->cohort);

    makeSubmission($this->assignment, $sara, ['version' => 1, 'is_late' => true, 'submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
    makeSubmission($this->assignment, $sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-20 10:00:00')]);
    makeSubmission($this->assignment, $omar, ['is_late' => true]);

    $stats = boardStats($this);

    expect($stats->submitted)->toBe(3)
        ->and($stats->late)->toBe(2)
        ->and($stats->awaitingGrading)->toBe(2);
});

it('D-145: الرقم يساوي ما سيفتحه الطابور — لا أكثر منه', function (): void {
    $sara = makeParticipant($this->cohort);
    $omar = makeParticipant($this->cohort);

    makeSubmission($this->assignment, $sara, ['version' => 1, 'submitted_at' => riyadhAt('2026-10-19 10:00:00')]);
    makeSubmission($this->assignment, $sara, ['version' => 2, 'submitted_at' => riyadhAt('2026-10-20 10:00:00')]);
    $first = makeSubmission($this->assignment, $omar);

    // Walk the queue from Omar's hand-in: it holds exactly one other row, Sara's newest.
    $next = app(App\Services\Grading\GradingQueue::class)->nextAssignmentSubmission($first);

    expect($next)->not->toBeNull()
        ->and((int) $next->version)->toBe(2)
        ->and(boardStats($this)->awaitingGrading)->toBe(2);
});
