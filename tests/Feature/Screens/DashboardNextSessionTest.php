<?php

declare(strict_types=1);

/**
 * The dashboard's next-session card agrees with the live page and the join
 * endpoint about when the join link opens.
 *
 * WHY THIS SUITE EXISTS
 * The card computed the window from the platform default, the other two from
 * the trainer's per-session figure (D-52). With 45 minutes set, the card said
 * "locked" while the endpoint let the trainee in; with 0, the card offered a
 * button the live page then refused (D-75). No test read the card at all.
 *
 * Every read also asserts the card was really built: the dashboard now catches
 * a throwing card and substitutes its empty form (D-66), which would pass every
 * "closed" assertion here for the wrong reason.
 *
 * @see BR-07, BR-24 · PRD §9.5.3, §9.10 · D-52, D-66, D-75
 */

use App\Http\Controllers\Participant\LiveController;
use App\Presenters\Participant\LiveSessionPresenter;
use App\Presenters\Participant\NextSessionPresenter;
use App\Services\Attendance\AttendanceWindow;

beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
    $this->start = riyadhAt('2026-10-12 18:00:00');
    $this->end = riyadhAt('2026-10-12 21:00:00');
});

function sessionWithWindow(object $test, ?int $minutes): App\Models\Session
{
    return makeSessionAt($test->start, $test->end, [
        'cohort_id' => $test->cohort->id,
        'zoom_url' => 'https://example.test/meeting',
        'join_opens_minutes' => $minutes,
    ]);
}

/** The card as the dashboard built it, after proving it was built. */
function cardAt(object $test, App\Models\Session $session, Carbon\CarbonImmutable $at): NextSessionPresenter
{
    freezeAt($at);
    $response = $test->actingAs($test->participant)->get(route('dashboard'))->assertOk();

    expect($response->viewData('failedBlocks'))->toBe([]);

    /** @var NextSessionPresenter $card */
    $card = $response->viewData('nextSession');
    expect($card->isMissing)->toBeFalse()->and($card->id)->toBe((string) $session->id);

    return $card;
}

it('D-52: بطاقة اللوحة تفتح الدخول على نافذة المدرّب — 45 دقيقة عند الحدّ ±1 ثانية', function (): void {
    $session = sessionWithWindow($this, 45);

    expect(cardAt($this, $session, $this->start->subMinutes(45)->subSecond())->joinWindowOpen)->toBeFalse()
        ->and(cardAt($this, $session, $this->start->subMinutes(45))->joinWindowOpen)->toBeTrue()
        ->and(cardAt($this, $session, $this->start->subMinutes(45))->joinOpensBeforeMinutes)->toBe(45);
});

it('D-52: صفر دقائق على اللوحة يعني صفرًا', function (): void {
    $session = sessionWithWindow($this, 0);

    expect(cardAt($this, $session, $this->start->subSecond())->joinWindowOpen)->toBeFalse()
        ->and(cardAt($this, $session, $this->start)->joinWindowOpen)->toBeTrue();
});

it('D-52: نافذة غير مضبوطة ترجع إلى الافتراضي', function (): void {
    $session = sessionWithWindow($this, null);
    $default = LiveController::JOIN_OPENS_BEFORE_START_MINUTES;

    expect(cardAt($this, $session, $this->start->subMinutes($default)->subSecond())->joinWindowOpen)->toBeFalse()
        ->and(cardAt($this, $session, $this->start->subMinutes($default))->joinWindowOpen)->toBeTrue();
});

it('BR-24: البطاقة وصفحة البثّ ونقطة الدخول تتّفق عند كل حدّ', function (): void {
    $session = sessionWithWindow($this, 45);
    $window = app(AttendanceWindow::class);

    $instants = [
        $this->start->subMinutes(45)->subSecond(), $this->start->subMinutes(45), $this->start->subMinutes(45)->addSecond(),
        $this->end->subSecond(), $this->end, $this->end->addSecond(),
    ];

    foreach ($instants as $at) {
        $card = cardAt($this, $session, $at);
        $live = LiveSessionPresenter::from($session, $window, $at);

        $join = $this->actingAs($this->participant)->post(route('live.join', $session));
        $admitted = $join->isRedirect('https://example.test/meeting');

        expect($card->joinWindowOpen)->toBe($live->joinWindowOpen, $at->toIso8601String())
            ->and($card->joinWindowOpen)->toBe($admitted, $at->toIso8601String());
    }
});

it('D-75: تلميح اللوحة يوافق العدد — صفر وواحد واثنان وخمسة وخمسة وأربعون', function (int $minutes): void {
    $session = sessionWithWindow($this, $minutes);
    freezeAt($this->start->subHours(3));

    $this->actingAs($this->participant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(e(trans_choice('dashboard.next_session.link_hint', $minutes, ['minutes' => $minutes])), false);
})->with([0, 1, 2, 5, 45]);
